<?php

namespace App\Modules\Api\V1\Zapier\Services;

use App\Models\FieldModelManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ZapierFieldMapperService
{
    /**
     * Cache TTL in minutes - fields are cached for 30 minutes
     * This prevents fetching fields on every 15-minute cron run
     */
    protected const CACHE_TTL_MINUTES = 30;

    /**
     * Get and cache fields for a module
     * Uses Laravel cache to persist across requests/jobs
     * Only fetches from database if cache is empty or expired
     */
    public function getModuleFields(string $module, string $organizationId): array
    {
        $cacheKey = "zapier_fields_{$module}_{$organizationId}";

        return Cache::remember($cacheKey, now()->addMinutes(self::CACHE_TTL_MINUTES), function () use ($module, $organizationId) {
            // Set organization context for custom fields - use base User model
            $user = \App\Models\User::where('organization_id', $organizationId)->first();
            if ($user) {
                Auth::shouldUse('web');
                Auth::login($user);
            }

            // Map module name from database enum to CRM model name
            $crmModuleName = $this->getCrmModuleName($module);

            $fieldManager = FieldModelManager::make($crmModuleName, 'EditView', true);
            $fields = $fieldManager->getFields();

            // Build comprehensive mapping
            $mappings = $this->buildFieldMappings($fields);

            Log::info("Zapier field mappings cached", [
                'module' => $module,
                'organization_id' => $organizationId,
                'field_count' => count($mappings['apiNames'] ?? []),
            ]);

            return $mappings;
        });
    }

    /**
     * Build multiple mapping strategies for field matching
     */
    protected function buildFieldMappings(array $fields): array
    {
        $mappings = [
            'apiToDb' => [],      // 'firstName' => 'first_name'
            'dbToApi' => [],      // 'first_name' => 'firstName'
            'apiNames' => [],     // All API field names (camelCase)
            'dbNames' => [],      // All DB field names (snake_case)
            'fieldMetadata' => [], // Full field metadata
            'aliases' => [],      // Common aliases/variations
        ];

        foreach ($fields as $apiName => $fieldModel) {
            $dbName = $fieldModel->getFieldName();
            $fieldType = $fieldModel->getFieldType();
            $isMandatory = $fieldModel->isMandatory();
            $isCustom = $fieldModel->isCustomField();

            // Primary mappings
            $mappings['apiToDb'][$apiName] = $dbName;
            $mappings['dbToApi'][$dbName] = $apiName;
            $mappings['apiNames'][] = $apiName;
            $mappings['dbNames'][] = $dbName;

            // Field metadata
            $mappings['fieldMetadata'][$apiName] = [
                'dbName' => $dbName,
                'apiName' => $apiName,
                'type' => $fieldType,
                'mandatory' => $isMandatory,
                'isCustom' => $isCustom,
                'label' => $fieldModel->getLabel(),
            ];

            // Build aliases for common field name variations
            $mappings['aliases'][$apiName] = $this->generateAliases($apiName, $dbName);
        }

        return $mappings;
    }

    /**
     * Generate common aliases for field matching
     */
    protected function generateAliases(string $apiName, string $dbName): array
    {
        $aliases = [$apiName, $dbName];

        // Add lowercase versions
        $aliases[] = strtolower($apiName);
        $aliases[] = strtolower($dbName);

        // Add snake_case variations
        $aliases[] = Str::snake($apiName);

        // Add camelCase variations
        $aliases[] = Str::camel($dbName);

        // Common field name variations
        $variations = [
            'firstName' => ['first_name', 'firstname', 'fname', 'first'],
            'lastName' => ['last_name', 'lastname', 'lname', 'last', 'surname'],
            'phoneNumber' => ['phone_number', 'phonenumber', 'phone', 'mobile', 'tel'],
            'email' => ['email_address', 'emailaddress', 'e_mail'],
            'organizationId' => ['organization_id', 'org_id', 'orgId', 'company_id'],
            'skuCode' => ['sku_code', 'skucode', 'sku', 'product_sku'],
        ];

        if (isset($variations[$apiName])) {
            $aliases = array_merge($aliases, $variations[$apiName]);
        }

        return array_unique($aliases);
    }

    /**
     * Map Zapier webhook data to CRM field format
     * Main mapping method called for each record
     */
    public function mapZapierDataToCrm(array $zapierData, string $module, string $organizationId): array
    {
        $mappings = $this->getModuleFields($module, $organizationId);
        $mappedData = [];
        $unmappedFields = [];

        foreach ($zapierData as $zapierKey => $value) {
            // Skip null/empty values unless they're explicitly set
            if ($value === null || $value === '') {
                continue;
            }

            $crmField = $this->findMatchingField($zapierKey, $mappings);

            if ($crmField) {
                $apiName = $crmField['apiName'];
                $fieldMeta = $mappings['fieldMetadata'][$apiName];

                // Transform value based on field type
                $transformedValue = $this->transformValue($value, $fieldMeta['type']);

                // Use API field name (camelCase) for RecordObject
                $mappedData[$apiName] = $transformedValue;
            } else {
                // Track unmapped fields for logging
                $unmappedFields[$zapierKey] = $value;
            }
        }

        // Log unmapped fields for debugging
        if (!empty($unmappedFields)) {
            Log::warning("Zapier unmapped fields", [
                'module' => $module,
                'organization_id' => $organizationId,
                'unmapped' => $unmappedFields,
            ]);
        }

        return $mappedData;
    }

    /**
     * Find matching CRM field for Zapier field name
     * Uses multiple matching strategies
     */
    protected function findMatchingField(string $zapierKey, array $mappings): ?array
    {
        // Strategy 1: Exact match on API name (camelCase)
        if (isset($mappings['fieldMetadata'][$zapierKey])) {
            return [
                'apiName' => $zapierKey,
                'dbName' => $mappings['fieldMetadata'][$zapierKey]['dbName'],
            ];
        }

        // Strategy 2: Exact match on DB name (snake_case)
        if (isset($mappings['dbToApi'][$zapierKey])) {
            $apiName = $mappings['dbToApi'][$zapierKey];
            return [
                'apiName' => $apiName,
                'dbName' => $zapierKey,
            ];
        }

        // Strategy 3: Case-insensitive match
        $lowerKey = strtolower($zapierKey);
        foreach ($mappings['apiNames'] as $apiName) {
            if (strtolower($apiName) === $lowerKey) {
                return [
                    'apiName' => $apiName,
                    'dbName' => $mappings['fieldMetadata'][$apiName]['dbName'],
                ];
            }
        }

        // Strategy 4: Alias matching (check all aliases)
        foreach ($mappings['fieldMetadata'] as $apiName => $meta) {
            $aliases = $mappings['aliases'][$apiName] ?? [];
            if (in_array(strtolower($zapierKey), array_map('strtolower', $aliases))) {
                return [
                    'apiName' => $apiName,
                    'dbName' => $meta['dbName'],
                ];
            }
        }

        // Strategy 5: Partial match (e.g., 'phone' matches 'phoneNumber')
        foreach ($mappings['apiNames'] as $apiName) {
            if (stripos($apiName, $zapierKey) !== false ||
                stripos($zapierKey, $apiName) !== false) {
                return [
                    'apiName' => $apiName,
                    'dbName' => $mappings['fieldMetadata'][$apiName]['dbName'],
                ];
            }
        }

        return null;
    }

    /**
     * Transform value based on field type
     */
    public function transformValue($value, string $fieldType)
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($fieldType) {
            'integer', 'Integer' => (int) $value,
            'boolean', 'Boolean' => $this->toBoolean($value),
            'timestamp', 'date', 'datetime' => $this->toDateTime($value),
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) ?: $value,
            'phone' => $this->normalizePhone($value),
            default => (string) $value,
        };
    }

    protected function toBoolean($value): bool
    {
        if (is_bool($value)) return $value;
        if (is_int($value)) return $value !== 0;
        if (is_string($value)) {
            $lower = strtolower($value);
            return in_array($lower, ['true', '1', 'yes', 'on']);
        }
        return false;
    }

    protected function toDateTime($value): ?string
    {
        if (empty($value)) return null;

        try {
            $date = \Carbon\Carbon::parse($value);
            return $date->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            Log::warning("Invalid date format", ['value' => $value, 'error' => $e->getMessage()]);
            return null;
        }
    }

    protected function normalizePhone($value): string
    {
        // Remove non-numeric characters except +
        return preg_replace('/[^0-9+]/', '', $value);
    }

    /**
     * Validate required fields
     */
    public function validateRequiredFields(array $mappedData, array $mappings): array
    {
        $errors = [];

        foreach ($mappings['fieldMetadata'] as $apiName => $meta) {
            if ($meta['mandatory'] && !isset($mappedData[$apiName])) {
                $errors[] = "Required field '{$apiName}' is missing";
            }
        }

        return $errors;
    }

    /**
     * Map module name from database enum to CRM model name
     */
    protected function getCrmModuleName(string $module): string
    {
        return match($module) {
            'contacts' => 'Contact',
            'leads' => 'Lead',
            'products' => 'Product',
            default => ucfirst($module),
        };
    }

    /**
     * Clear cache (useful when fields are updated)
     * Clears Laravel cache entries for field mappings
     */
    public function clearCache(string $module = null, string $organizationId = null): void
    {
        if ($module && $organizationId) {
            // Clear specific module/organization cache
            $cacheKey = "zapier_fields_{$module}_{$organizationId}";
            Cache::forget($cacheKey);
            
            Log::info("Zapier field cache cleared", [
                'module' => $module,
                'organization_id' => $organizationId,
            ]);
        } else {
            // Clear all Zapier field caches (use cache tags if available, otherwise pattern-based)
            // Note: This is a simple implementation. For production, consider using cache tags
            // or maintaining a list of cache keys to invalidate
            Log::warning("Clearing all Zapier field caches - consider specifying module and organization");
            
            // If using Redis with tags, you could do:
            // Cache::tags(['zapier_fields'])->flush();
            // For now, we'll just log - manual cache clearing per module is recommended
        }
    }
}
