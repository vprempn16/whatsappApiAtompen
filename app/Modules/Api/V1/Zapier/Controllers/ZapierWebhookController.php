<?php

namespace App\Modules\Api\V1\Zapier\Controllers;

use App\Http\Controllers\ApiController;
use App\Modules\Api\V1\Zapier\Models\ZapierImportBatch;
use App\Modules\Api\V1\Zapier\Models\ZapierConnectedApp;
use App\Modules\Api\V1\Zapier\Models\OrganizationZapierSetting;
use App\Modules\Api\V1\Zapier\Services\ZapierCachedImportService;
use App\Modules\Api\V1\Zapier\Services\ZapierImportService;
use App\Services\AuditLogService;
use App\Constants\AuditLogEventType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class ZapierWebhookController extends ApiController
{
    public function __construct(protected ZapierCachedImportService $cachedImportService)
    {
    }

    /**
     * Log Zapier webhook action to audit log
     * Note: This is a public endpoint, so we may not have an authenticated user
     */
    protected function logZapierWebhookAction(string $action, string $organizationId, array $metadata = []): void
    {
        try {
            $auditService = new AuditLogService();
            
            // Directly insert into audit_log since we may not have an authenticated user
            DB::table('audit_log')->insert([
                'event_type' => AuditLogEventType::CREATE,
                'entity_name' => 'Zapier',
                'entity_id' => $organizationId ?? 'system',
                'organization_id' => $organizationId,
                'action_by' => null, // No authenticated user for webhooks
                'action_details' => $action,
                'old_value' => json_encode([], JSON_UNESCAPED_SLASHES),
                'new_value' => json_encode($metadata, JSON_UNESCAPED_SLASHES),
                'more_info' => json_encode(array_merge([
                    'action' => $action,
                    'ip' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                    'source' => 'webhook',
                ], $metadata), JSON_UNESCAPED_SLASHES),
                'ip_address' => request()->ip() ?? '127.0.0.1',
                'user_agent' => request()->userAgent() ?? 'zapier-webhook',
                'created_at' => now(),
                'updated_at' => now(),
                'action_timestamp' => now()->setTimezone('Asia/Kolkata')->format('Y-m-d H:i:s'),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to log Zapier webhook action', [
                'action' => $action,
                'organization_id' => $organizationId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle incoming webhook from Zapier
     * 
     * Route: POST /api/v1/zapier/webhook/{module}
     * 
     * Authentication: API key via Authorization header (Bearer <API_KEY>)
     * API key is validated against ZAPIER_API_KEY environment variable
     * 
     * @param Request $request
     * @param string $module Module name: contacts, leads, or products
     * @return JsonResponse
     */
    public function handle(Request $request, string $module): JsonResponse
    {
        Log::channel('zapier')->info('Zapier webhook received', [ 'request' => $request->all() ]);
        try {
            // Validate module
            $module = Str::plural(strtolower($module));

            $allowedModules = ZapierImportService::getSupportedModules();
            if (!in_array($module, $allowedModules)) {
                return $this->error(
                    'Invalid module. Must be one of: ' . implode(', ', $allowedModules)
                );
            }

            // Validate API key and get organization ID
            $organizationId = $this->validateApiKeyAndGetOrganization($request, $module);
            if (!$organizationId) {
                return $this->error('Invalid API key or module not enabled. Please verify your API key is correctly configured.');
            }

            // Get webhook payload
            $payload = $request->all();

            // Check if payload is empty or only has empty 'data' field
            if (empty($payload) || (count($payload) === 1 && isset($payload['data']) && empty(trim($payload['data'])))) {
                return $this->error('No data received in webhook payload');
            }

            // Check if Zapier sent data as a single string in 'data' field (incorrect configuration)
            // Try to parse it as a workaround
            if (isset($payload['data']) && is_string($payload['data']) && count($payload) === 1) {
                Log::channel('zapier')->warning('Zapier webhook - data received as string, attempting to parse', [
                    'organization_id' => $organizationId,
                    'module' => $module,
                    'data_preview' => substr($payload['data'], 0, 200),
                ]);
                
                // Try to parse the malformed JSON-like string
                $parsed = $this->parseZapierStringData($payload['data']);
                
                if ($parsed === null) {
                    return $this->error(
                        'Invalid payload format. Zapier is sending data as a single concatenated string. ' .
                        'Please configure your Zap to map each Google Sheets column to a separate JSON field. ' .
                        'Expected format: {"external_id": "GS001", "first_name": "Jane", "last_name": "Doe", "email": "jane@example.com", "phone_number": "+15551234567"}. ' .
                        'In your Zapier "Webhooks by Zapier" action, map each Google Sheets field individually instead of using a single "data" field.'
                    );
                }
                
                // Replace payload with parsed data
                $payload = $parsed;
                Log::channel('zapier')->info('Zapier webhook - successfully parsed string data', [
                    'organization_id' => $organizationId,
                    'module' => $module,
                    'parsed_data' => $parsed,
                ]);
            }

            // Handle batch webhooks (array of records) or single record
            $records = isset($payload[0]) && is_array($payload[0]) ? $payload : [$payload];

            // external_id is optional - if not provided, we'll generate one based on record index
            // This allows records without external_id to still be processed
            foreach ($records as $index => &$record) {
                if (empty($record['external_id']) && empty($record['id'])) {
                    // Generate a temporary external_id if not provided
                    $record['external_id'] = 'zapier-' . time() . '-' . $index;
                } elseif (empty($record['external_id']) && !empty($record['id'])) {
                    // Use 'id' field as external_id if provided
                    $record['external_id'] = $record['id'];
                }
            }
            unset($record); // Break reference

            Log::channel('zapier')->info('Zapier webhook received', [
                'organization_id' => $organizationId,
                'module' => $module,
                'record_count' => count($records),
                'ip' => $request->ip(),
            ]);

            // Process each record
            $externalSource = 'zapier';
            $syncMode = 'incremental'; // Webhooks are always incremental

            // Get or create connected app
            $connectedApp = ZapierConnectedApp::findOrCreate($organizationId, $externalSource, [$module]);

            // Create import batch for tracking (status stays running until processed)
            $batch = ZapierImportBatch::create([
                'organization_id' => $organizationId,
                'module' => $module,
                'external_source' => $externalSource,
                'sync_mode' => $syncMode,
                'status' => 'running',
                'started_at' => now(),
            ]);

            // Cache payloads instead of immediate processing
            $cached = $this->cachedImportService->cachePayloads(
                $batch,
                $organizationId,
                $module,
                $records,
                $externalSource
            );

            // Update connected app sync timestamp
            $connectedApp->updateLastSynced();

            // Log webhook receipt
            $this->logZapierWebhookAction('Zapier webhook received and cached', $organizationId, [
                'module' => $module,
                'batch_id' => $batch->id,
                'record_count' => count($records),
                'external_source' => $externalSource,
                'sync_mode' => $syncMode,
                'cached_record_ids' => $cached->pluck('id')->toArray(),
            ]);

            return $this->success([
                'message' => 'Webhook received and cached for review',
                'batch_id' => $batch->id,
                'record_ids' => $cached->pluck('id'),
                'status' => 'cached',
            ]); // 202 Accepted - processing deferred

        } catch (\Exception $e) {
            Log::channel('zapier')->error('Zapier webhook error', [
                'module' => $module,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error('Webhook processing failed: ' . $e->getMessage());
        }
    }

    /**
     * Validate API key and get organization ID from database
     * 
     * Looks up the API key in organization_zapier_settings table
     * and returns the organization_id for that API key.
     * Also verifies that the requested module is enabled for that organization.
     * 
     * @param Request $request
     * @param string $module Module name to verify is enabled
     * @return string|null Organization ID if valid and module enabled, null otherwise
     */
    protected function validateApiKeyAndGetOrganization(Request $request, string $module): ?string
    {
        // Get API key from request headers
        $providedApiKey = $request->header('Authorization') 
                       ?? $request->header('X-Zapier-API-Key')
                       ?? $request->query('api_key');
        
        if (empty($providedApiKey)) {
            Log::channel('zapier')->warning('Zapier webhook - no API key provided', [
                'ip' => $request->ip(),
                'module' => $module,
            ]);
            return null;
        }

        // Remove 'Bearer ' prefix if present and trim whitespace
        $providedApiKey = preg_replace('/^Bearer\s+/i', '', $providedApiKey);
        $providedApiKey = trim($providedApiKey);

        // Look up organization by API key in database
        $settings = $this->findOrganizationByApiKey($providedApiKey);
        if (!$settings) {
            // Log the failed attempt (masked for security)
            $maskedKey = strlen($providedApiKey) > 8 ? substr($providedApiKey, 0, 4) . '...' . substr($providedApiKey, -4) : '***';
            Log::channel('zapier')->warning('Zapier webhook authentication failed - invalid API key', [
                'ip' => $request->ip(),
                'module' => $module,
                'key_preview' => $maskedKey,
            ]);
            return null;
        }
        // Verify the module is enabled for this organization
        if (!$settings->isModuleEnabled($module)) {
            Log::channel('zapier')->warning('Zapier webhook - module not enabled for organization', [
                'ip' => $request->ip(),
                'organization_id' => $settings->organization_id,
                'module' => $module,
            ]);
            return null;
        }

        Log::channel('zapier')->info('Zapier webhook - API key validated, organization found', [
            'ip' => $request->ip(),
            'organization_id' => $settings->organization_id,
            'module' => $module,
        ]);

        return $settings->organization_id;
    }

    /**
     * Find organization settings by API key from database
     * 
     * @param string $apiKey Plain text API key
     * @return OrganizationZapierSetting|null
     */
    protected function findOrganizationByApiKey(string $apiKey): ?OrganizationZapierSetting
    {
        $apiKey = trim($apiKey);
        
        if (empty($apiKey)) {
            Log::channel('zapier')->debug('API key is empty after trimming');
            return null;
        }

        $apiKeyHash = hash('sha256', $apiKey);

        $hashedMatch = OrganizationZapierSetting::withoutGlobalScopes()
            ->where('api_key_hash', $apiKeyHash)
            ->first();

        if ($hashedMatch) {
            Log::channel('zapier')->info('API key matched by hash lookup', [
                'organization_id' => $hashedMatch->organization_id,
            ]);
            return $hashedMatch;
        }

        // Get all Zapier settings (without ordering to avoid issues with non-incrementing primary key)
        $allSettings = OrganizationZapierSetting::withoutGlobalScopes()->get();
        
        if ($allSettings->isEmpty()) {
            Log::channel('zapier')->warning('No Zapier settings found in database');
            return null;
        }

        $maskedProvided = strlen($apiKey) > 8 ? substr($apiKey, 0, 4) . '...' . substr($apiKey, -4) : '***';
        
        Log::channel('zapier')->debug('Searching for API key in database', [
            'total_settings' => $allSettings->count(),
            'provided_key_length' => strlen($apiKey),
            'provided_key_preview' => $maskedProvided,
        ]);

        foreach ($allSettings as $setting) {
            try {
                // Decrypt and compare API key
                $decryptedKey = $setting->zapier_api_key; // Accessor will decrypt
                $decryptedKey = trim($decryptedKey ?? '');
                
                if (empty($decryptedKey)) {
                    Log::channel('zapier')->debug('Decrypted key is empty for organization', [
                        'organization_id' => $setting->organization_id ?? 'unknown',
                    ]);
                    continue;
                }
                
                $maskedDecrypted = strlen($decryptedKey) > 8 ? substr($decryptedKey, 0, 4) . '...' . substr($decryptedKey, -4) : '***';
                
                Log::channel('zapier')->debug('Comparing API keys', [
                    'organization_id' => $setting->organization_id ?? 'unknown',
                    'provided_key_length' => strlen($apiKey),
                    'decrypted_key_length' => strlen($decryptedKey),
                    'provided_preview' => $maskedProvided,
                    'decrypted_preview' => $maskedDecrypted,
                    'keys_match' => hash_equals($decryptedKey, $apiKey),
                ]);
                
                // Use hash_equals for timing-safe comparison
                if (hash_equals($decryptedKey, $apiKey)) {
                    Log::channel('zapier')->info('API key matched in database', [
                        'organization_id' => $setting->organization_id,
                    ]);
                    $setting->api_key_hash = $apiKeyHash;
                    $setting->save();
                    return $setting;
                }
            } catch (\Exception $e) {
                Log::channel('zapier')->error('Failed to decrypt API key for organization', [
                    'organization_id' => $setting->organization_id ?? 'unknown',
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                continue;
            }
        }

        Log::channel('zapier')->debug('API key did not match any organization settings');
        return null;
    }

    /**
     * Parse malformed JSON-like string from Zapier
     * 
     * Handles formats like:
     * "{external_id : GS001\nfirst_name  : Janelast_name :Doe\nemail : jane.doe@example.com\nphone_number : +15551234567}"
     * 
     * @param string $dataString The malformed JSON string
     * @return array|null Parsed data as array, or null if parsing fails
     */
    protected function parseZapierStringData(string $dataString): ?array
    {
        try {
            // Remove surrounding braces if present
            $dataString = trim($dataString);
            if (strpos($dataString, '{') === 0) {
                $dataString = substr($dataString, 1);
            }
            if (substr($dataString, -1) === '}') {
                $dataString = substr($dataString, 0, -1);
            }
            
            $result = [];
            
            // First, try to extract all key-value pairs using regex
            // Pattern matches: "key : value" where key is alphanumeric/underscore
            // This handles cases where fields might be concatenated like "Janelast_name :Doe"
            // We need to be more aggressive in finding field boundaries
            
            // Step 1: Find all field patterns (key : value)
            // Use a more precise pattern that looks for field names followed by colon
            $fieldPattern = '/([a-z_]+)\s*:\s*/i';
            
            // Find all field positions
            $fieldPositions = [];
            preg_match_all($fieldPattern, $dataString, $fieldMatches, PREG_OFFSET_CAPTURE);
            
            for ($i = 0; $i < count($fieldMatches[1]); $i++) {
                $fieldName = trim($fieldMatches[1][$i][0]);
                $fieldStart = $fieldMatches[0][$i][1] + strlen($fieldMatches[0][$i][0]); // Position after ": "
                
                // Find where this field's value ends (start of next field or end of string)
                $nextFieldStart = isset($fieldMatches[0][$i + 1]) 
                    ? $fieldMatches[0][$i + 1][1] 
                    : strlen($dataString);
                
                $fieldValue = trim(substr($dataString, $fieldStart, $nextFieldStart - $fieldStart));
                $fieldValue = rtrim($fieldValue, ',}');
                
                // Check if value contains another field pattern (concatenated)
                // Example: "Janelast_name :Doe" - the value "Jane" is followed by "last_name :Doe"
                // Try multiple patterns to catch different concatenation formats
                $concatenated = null;
                
                // Pattern 1: "ValueFieldName :Value" (most common)
                if (preg_match('/^(.+?)([a-z_]+)\s*:\s*(.+)$/i', $fieldValue, $matches)) {
                    $concatenated = $matches;
                }
                // Pattern 2: "ValueFieldName:Value" (no spaces)
                elseif (preg_match('/^(.+?)([a-z_]+):(.+)$/i', $fieldValue, $matches)) {
                    $concatenated = $matches;
                }
                // Pattern 3: Look for known field names in the value
                elseif (preg_match('/^(.+?)(first_name|last_name|email|phone_number|external_id)\s*:\s*(.+)$/i', $fieldValue, $matches)) {
                    $concatenated = $matches;
                }
                
                if ($concatenated && count($concatenated) >= 4) {
                    // This value is actually two fields concatenated
                    // Store the first part as the current field's value
                    $firstValue = trim($concatenated[1]);
                    if (!empty($firstValue)) {
                        $result[$fieldName] = $firstValue;
                    }
                    
                    // The second part is a new field that was concatenated
                    $nextKey = trim($concatenated[2]);
                    $nextValue = trim($concatenated[3]);
                    $nextValue = rtrim($nextValue, ',}');
                    if (!empty($nextValue)) {
                        $result[$nextKey] = trim($nextValue);
                    }
                } else {
                    // Normal case - just store the value
                    if (!empty($fieldValue)) {
                        $result[$fieldName] = $fieldValue;
                    }
                }
            }
            
            // If we got at least one field, return the result
            if (!empty($result)) {
                return $result;
            }
            
            // Fallback: Split by newlines and try to parse each line
            $lines = preg_split('/[\r\n]+/', $dataString);
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) {
                    continue;
                }
                
                // Try to match pattern: "key : value"
                if (preg_match('/^([a-z_]+)\s*:\s*(.+)$/i', $line, $matches)) {
                    $key = trim($matches[1]);
                    $value = trim($matches[2]);
                    $value = rtrim($value, ',}');
                    $result[$key] = $value;
                }
            }
            
            return !empty($result) ? $result : null;
        } catch (\Exception $e) {
            Log::channel('zapier')->error('Failed to parse Zapier string data', [
                'error' => $e->getMessage(),
                'data_preview' => substr($dataString, 0, 200),
            ]);
            return null;
        }
    }
}
