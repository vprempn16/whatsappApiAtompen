<?php

namespace App\Modules\Api\V1\Zapier\Controllers;

use App\Http\Controllers\ApiController;
use App\Modules\Api\V1\Zapier\Models\OrganizationZapierSetting;
use App\Modules\Api\V1\Zapier\Services\ZapierApiClient;
use App\Services\AuditLogService;
use App\Constants\AuditLogEventType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class ZapierSettingsController extends ApiController
{
    /**
     * Log Zapier action to audit log
     */
    protected function logZapierAction(string $action, array $metadata = [], ?string $eventType = AuditLogEventType::UPDATE, ?array $oldValues = []): void
    {
        try {
            $auditService = new AuditLogService();
            $organizationId = auth()->user()->organization_id ?? null;
            
            $auditService->create([
                'entity_name' => 'Zapier',
                'entity_id' => $organizationId ?? 'system',
                'organization_id' => $organizationId,
                'old_values' => $oldValues,
                'new_values' => $metadata,
                'is_update' => true,
                'more_info' => array_merge([
                    'action' => $action,
                    'ip' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                ], $metadata),
            ], $action, $eventType);
        } catch (\Exception $e) {
            Log::error('Failed to log Zapier action', [
                'action' => $action,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get Zapier settings for current organization
     */
    public function show(Request $request)
    {
        try {
            $organizationId = auth()->user()->organization_id;

            $settings = OrganizationZapierSetting::forOrganization($organizationId)->first();

            if (!$settings) {
                $this->logZapierAction('Zapier settings viewed', [
                    'enabled' => false,
                    'has_settings' => false,
                ], AuditLogEventType::UPDATE);

                return $this->success([
                    'enabled' => false,
                    'modules' => [
                        'contacts' => false,
                        'leads' => false,
                        'products' => false,
                    ],
                ]);
            }

            $result = [
                'enabled' => true,
                'modules' => [
                    'contacts' => $settings->contacts_enabled,
                    'leads' => $settings->leads_enabled,
                    'products' => $settings->products_enabled,
                ],
                'has_api_key' => !empty($settings->zapier_api_key),
                'updated_at' => $settings->updated_at,
            ];

            $this->logZapierAction('Zapier settings viewed', [
                'has_api_key' => !empty($settings->zapier_api_key),
                'contacts_enabled' => $settings->contacts_enabled,
                'leads_enabled' => $settings->leads_enabled,
                'products_enabled' => $settings->products_enabled,
            ], AuditLogEventType::UPDATE);

            return $this->success($result);

        } catch (\Exception $e) {
            return $this->error('Failed to fetch Zapier settings: ' . $e->getMessage());
        }
    }

    /**
     * Update Zapier settings
     */
    public function update(Request $request)
    {
        try {
            $organizationId = auth()->user()->organization_id;

            $validated = $request->validate([
                'zapier_api_key' => 'sometimes|string',
                'contacts_enabled' => 'sometimes|boolean',
                'leads_enabled' => 'sometimes|boolean',
                'products_enabled' => 'sometimes|boolean',
            ]);

            $settings = OrganizationZapierSetting::forOrganization($organizationId)->first();

            // Capture old values for audit log
            $oldValues = [];
            if ($settings) {
                $oldValues = [
                    'contacts_enabled' => $settings->contacts_enabled,
                    'leads_enabled' => $settings->leads_enabled,
                    'products_enabled' => $settings->products_enabled,
                    'has_api_key' => !empty($settings->zapier_api_key),
                ];
            }

            if (!$settings) {
                $settings = new OrganizationZapierSetting();
                $settings->organization_id = $organizationId;
            }

            // Update fields
            if (isset($validated['zapier_api_key'])) {
                $settings->zapier_api_key = $validated['zapier_api_key'];
            }

            if (isset($validated['contacts_enabled'])) {
                $settings->contacts_enabled = $validated['contacts_enabled'];
            }

            if (isset($validated['leads_enabled'])) {
                $settings->leads_enabled = $validated['leads_enabled'];
            }

            if (isset($validated['products_enabled'])) {
                $settings->products_enabled = $validated['products_enabled'];
            }

            $settings->save();

            // Log audit entry
            $newValues = [
                'contacts_enabled' => $settings->contacts_enabled,
                'leads_enabled' => $settings->leads_enabled,
                'products_enabled' => $settings->products_enabled,
                'has_api_key' => !empty($settings->zapier_api_key),
            ];
            $this->logZapierAction('Zapier settings updated', $newValues, AuditLogEventType::UPDATE, $oldValues);

            Log::channel('zapier')->info('Zapier settings updated', [
                'organization_id' => $organizationId,
                'settings' => $validated,
            ]);

            return $this->success([
                'message' => 'Zapier settings updated successfully',
                'settings' => [
                    'contacts_enabled' => $settings->contacts_enabled,
                    'leads_enabled' => $settings->leads_enabled,
                    'products_enabled' => $settings->products_enabled,
                ],
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error('Validation failed', $e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to update Zapier settings: ' . $e->getMessage());
        }
    }

    /**
     * Test API connection
     */
    public function testConnection(Request $request)
    {
        try {
            $organizationId = auth()->user()->organization_id;

            $validated = $request->validate([
                'zapier_api_key' => 'required|string',
                'endpoint' => 'sometimes|string|url',
            ]);

            $apiClient = new ZapierApiClient($validated['zapier_api_key']);
            $endpoint = $validated['endpoint'] ?? '/test';

            $isConnected = $apiClient->testConnection($endpoint);

            $this->logZapierAction('Zapier API connection tested', [
                'endpoint' => $endpoint,
                'connected' => $isConnected,
            ], AuditLogEventType::UPDATE);

            if ($isConnected) {
                return $this->success([
                    'message' => 'Connection successful',
                    'connected' => true,
                ]);
            } else {
                return $this->error('Connection failed');
            }

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return $this->error('Connection test failed: ' . $e->getMessage());
        }
    }

    /**
     * Generate new API key
     */
    public function generateApiKey(Request $request)
    {
        try {
            $organizationId = auth()->user()->organization_id;

            // Generate a secure random API key
            $apiKey = bin2hex(random_bytes(32)); // 64 character hex string

            $settings = OrganizationZapierSetting::forOrganization($organizationId)->first();

            if (!$settings) {
                $settings = new OrganizationZapierSetting();
                $settings->organization_id = $organizationId;
            }

            $settings->zapier_api_key = $apiKey;
            $settings->save();

            Log::channel('zapier')->info('API key generated', [
                'organization_id' => $organizationId,
            ]);

            $this->logZapierAction('Zapier API key generated', [
                'api_key_length' => strlen($apiKey),
                'api_key_preview' => substr($apiKey, 0, 4) . '...' . substr($apiKey, -4),
            ], AuditLogEventType::CREATE);

            return $this->success([
                'message' => 'API key generated successfully',
                'api_key' => $apiKey, // Only returned once
            ]);

        } catch (\Exception $e) {
            return $this->error('Failed to generate API key: ' . $e->getMessage());
        }
    }

    /**
     * Get webhook URL for organization
     */
    public function getWebhookUrl(Request $request)
    {
        try {
            $organizationId = auth()->user()->organization_id;
            $module = $request->input('module');

            $settings = OrganizationZapierSetting::forOrganization($organizationId)->first();

            if (!$settings || empty($settings->zapier_api_key)) {
                return $this->error('Zapier integration not configured. Please generate an API key first.');
            }

            $baseUrl = config('app.url');
            $webhookUrl = rtrim($baseUrl, '/') . '/api/v1/zapier/webhook/' . ($module ?? '{module}');

            $this->logZapierAction('Zapier webhook URL retrieved', [
                'module' => $module,
                'webhook_url' => $webhookUrl,
            ], AuditLogEventType::UPDATE);

            return $this->success([
                'webhook_url' => $webhookUrl,
                'module' => $module,
                'instructions' => 'Use this URL in your Zapier webhook configuration. Replace {module} with: contacts, leads, or products.',
            ]);

        } catch (\Exception $e) {
            return $this->error('Failed to generate webhook URL: ' . $e->getMessage());
        }
    }

    /**
     * Get available triggers and actions
     */
    public function getTriggersAndActions(Request $request)
    {
        try {
            $organizationId = auth()->user()->organization_id;

            $settings = OrganizationZapierSetting::forOrganization($organizationId)->first();

            $triggers = [];
            $actions = [];

            if ($settings) {
                $enabledModules = $settings->getEnabledModules();

                foreach ($enabledModules as $module) {
                    $triggers[] = [
                        'id' => "new_{$module}",
                        'name' => "New " . ucfirst($module),
                        'description' => "Triggered when a new {$module} is created in Zapier",
                        'module' => $module,
                        'type' => 'webhook',
                    ];

                    $triggers[] = [
                        'id' => "updated_{$module}",
                        'name' => "Updated " . ucfirst($module),
                        'description' => "Triggered when a {$module} is updated in Zapier",
                        'module' => $module,
                        'type' => 'webhook',
                    ];

                    $actions[] = [
                        'id' => "create_{$module}",
                        'name' => "Create " . ucfirst($module),
                        'description' => "Create a new {$module} in CRM",
                        'module' => $module,
                        'type' => 'create',
                    ];

                    $actions[] = [
                        'id' => "update_{$module}",
                        'name' => "Update " . ucfirst($module),
                        'description' => "Update an existing {$module} in CRM",
                        'module' => $module,
                        'type' => 'update',
                    ];

                    $actions[] = [
                        'id' => "search_{$module}",
                        'name' => "Search " . ucfirst($module),
                        'description' => "Search for {$module} in CRM",
                        'module' => $module,
                        'type' => 'search',
                    ];
                }
            }

            $this->logZapierAction('Zapier triggers and actions retrieved', [
                'triggers_count' => count($triggers),
                'actions_count' => count($actions),
                'enabled_modules' => $enabledModules ?? [],
            ], AuditLogEventType::UPDATE);

            return $this->success([
                'triggers' => $triggers,
                'actions' => $actions,
                'modules' => $enabledModules ?? [],
            ]);

        } catch (\Exception $e) {
            return $this->error('Failed to fetch triggers and actions: ' . $e->getMessage());
        }
    }

    /**
     * Get integration statistics
     */
    public function getStatistics(Request $request)
    {
        try {
            $organizationId = auth()->user()->organization_id;

            $settings = OrganizationZapierSetting::forOrganization($organizationId)->first();

            if (!$settings) {
                return $this->success([
                    'total_batches' => 0,
                    'total_records' => 0,
                    'successful_imports' => 0,
                    'failed_imports' => 0,
                    'last_sync' => null,
                ]);
            }

            $batches = \App\Modules\Api\V1\Zapier\Models\ZapierImportBatch::forOrganization($organizationId);
            $totalBatches = $batches->count();
            $successfulBatches = $batches->where('status', 'completed')->count();
            $failedBatches = $batches->where('status', 'failed')->count();

            $requestLogs = \App\Modules\Api\V1\Zapier\Models\ZapierRequestLog::forOrganization($organizationId);
            $totalRecords = $requestLogs->count();
            $successfulImports = $requestLogs->successful()->count();
            $failedImports = $requestLogs->failed()->count();

            $lastSync = \App\Modules\Api\V1\Zapier\Models\ZapierConnectedApp::forOrganization($organizationId)
                ->orderBy('last_synced_at', 'desc')
                ->first();

            $connectedAppsCount = \App\Modules\Api\V1\Zapier\Models\ZapierConnectedApp::forOrganization($organizationId)->count();
            $successRate = $totalRecords > 0 ? round(($successfulImports / $totalRecords) * 100, 2) : 0;

            $this->logZapierAction('Zapier statistics retrieved', [
                'total_batches' => $totalBatches,
                'total_records' => $totalRecords,
                'successful_imports' => $successfulImports,
                'failed_imports' => $failedImports,
                'success_rate' => $successRate,
                'connected_apps' => $connectedAppsCount,
            ], AuditLogEventType::UPDATE);

            return $this->success([
                'total_batches' => $totalBatches,
                'total_records' => $totalRecords,
                'successful_imports' => $successfulImports,
                'failed_imports' => $failedImports,
                'success_rate' => $successRate,
                'last_sync' => $lastSync?->last_synced_at?->toIso8601String(),
                'connected_apps' => $connectedAppsCount,
            ]);

        } catch (\Exception $e) {
            return $this->error('Failed to fetch statistics: ' . $e->getMessage());
        }
    }
}
