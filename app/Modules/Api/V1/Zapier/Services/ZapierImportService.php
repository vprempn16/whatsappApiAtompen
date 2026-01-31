<?php

namespace App\Modules\Api\V1\Zapier\Services;

use App\Modules\Api\V1\Zapier\Models\OrganizationZapierSetting;
use App\Modules\Api\V1\Zapier\Models\ZapierImportBatch;
use App\Modules\Api\V1\Zapier\Models\ZapierConnectedApp;
use App\Jobs\Zapier\ProcessZapierImportBatch;
use Illuminate\Support\Facades\Log;

class ZapierImportService
{
    protected ?ZapierApiClient $apiClient = null;
    protected ZapierFieldMapperService $fieldMapper;
    protected ZapierIdempotencyService $idempotencyService;

    protected static array $supportedModules = [
        'contacts' => ['model' => 'Contact', 'matching_fields' => ['email', 'phoneNumber']],
        'leads' => ['model' => 'Lead', 'matching_fields' => ['email', 'phoneNumber']],
        'products' => ['model' => 'Product', 'matching_fields' => ['skuCode']],
    ];

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

    public function __construct(
        ZapierFieldMapperService $fieldMapper,
        ZapierIdempotencyService $idempotencyService
    ) {
        $this->fieldMapper = $fieldMapper;
        $this->idempotencyService = $idempotencyService;
    }

    /**
     * Process imports for an organization
     */
    public function processOrganizationImports(string $organizationId): void
    {
        $settings = OrganizationZapierSetting::forOrganization($organizationId)->first();

        if (!$settings) {
            Log::info("No Zapier settings found for organization", ['organization_id' => $organizationId]);
            return;
        }

        if (empty($settings->getEnabledModules())) {
            Log::info("No modules enabled for Zapier import", ['organization_id' => $organizationId]);
            return;
        }

        // Initialize API client with organization's API key
        $this->apiClient = new ZapierApiClient($settings->zapier_api_key);

        $enabledModules = $settings->getEnabledModules();

        foreach ($enabledModules as $module) {
            try {
                $this->processModuleImport($organizationId, $module, $settings);
            } catch (\Exception $e) {
                Log::error("Failed to process module import", [
                    'organization_id' => $organizationId,
                    'module' => $module,
                    'error' => $e->getMessage(),
                ]);
                // Continue with other modules
            }
        }
    }

    /**
     * Process import for a specific module
     */
    protected function processModuleImport(
        string $organizationId,
        string $module,
        OrganizationZapierSetting $settings
    ): void {
        // Get or create connected app
        $externalSource = 'zapier'; // Default, can be configured per organization
        $connectedApp = ZapierConnectedApp::findOrCreate($organizationId, $externalSource, [$module]);

        // Determine sync mode
        $syncMode = $this->determineSyncMode($connectedApp, $module);

        // Create import batch
        $batch = ZapierImportBatch::create([
            'organization_id' => $organizationId,
            'module' => $module,
            'external_source' => $externalSource,
            'sync_mode' => $syncMode,
            'status' => 'running',
            'started_at' => now(),
        ]);

        Log::info("Created Zapier import batch", [
            'batch_id' => $batch->id,
            'organization_id' => $organizationId,
            'module' => $module,
            'sync_mode' => $syncMode,
        ]);

        // Dispatch batch processing job
        ProcessZapierImportBatch::dispatch($batch->id, $organizationId, $module, $externalSource, $syncMode);
    }

    /**
     * Determine sync mode (initial vs incremental)
     */
    protected function determineSyncMode(ZapierConnectedApp $connectedApp, string $module): string
    {
        // If module was never synced before, use initial sync
        if (!$connectedApp->hasModule($module) || !$connectedApp->last_synced_at) {
            return 'initial';
        }

        // Otherwise use incremental
        return 'incremental';
    }

    /**
     * Get last sync timestamp for incremental sync
     */
    public function getLastSyncTimestamp(string $organizationId, string $module, string $externalSource): ?string
    {
        $connectedApp = ZapierConnectedApp::where('organization_id', $organizationId)
            ->where('external_source', $externalSource)
            ->first();

        return $connectedApp?->last_synced_at?->toIso8601String();
    }

    /**
     * Update last sync timestamp
     */
    public function updateLastSyncTimestamp(string $organizationId, string $externalSource): void
    {
        $connectedApp = ZapierConnectedApp::where('organization_id', $organizationId)
            ->where('external_source', $externalSource)
            ->first();

        if ($connectedApp) {
            $connectedApp->updateLastSynced();
        }
    }

    /**
     * Get supported modules
     */
    public static function getSupportedModules(): array
    {
        return array_keys(self::$supportedModules);
    }

    /**
     * Check if module is supported
     */
    public static function isModuleSupported(string $module): bool
    {
        return isset(self::$supportedModules[$module]);
    }

    /**
     * Get module configuration
     */
    public static function getModuleConfig(string $module): ?array
    {
        return self::$supportedModules[$module] ?? null;
    }
}
