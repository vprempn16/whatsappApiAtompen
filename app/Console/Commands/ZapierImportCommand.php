<?php

namespace App\Console\Commands;

use App\Modules\Api\V1\Zapier\Models\OrganizationZapierSetting;
use App\Modules\Api\V1\Zapier\Services\ZapierImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ZapierImportCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'zapier:import 
                            {--organization= : Process imports for specific organization ID}
                            {--module= : Process imports for specific module (contacts/leads/products)}
                            {--force : Force import even if already running}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import data from Zapier for enabled organizations and modules';

    /**
     * Execute the console command.
     */
    public function handle(ZapierImportService $importService): int
    {
        $this->info('Starting Zapier import process...');

        try {
            // Get organizations to process
            $organizations = $this->getOrganizationsToProcess();

            if (empty($organizations)) {
                $this->info('No organizations with Zapier enabled found.');
                return Command::SUCCESS;
            }

            $this->info("Found " . count($organizations) . " organization(s) with Zapier enabled.");

            $processedCount = 0;
            $errorCount = 0;

            foreach ($organizations as $organizationId) {
                try {
                    $this->info("Processing organization: {$organizationId}");

                    // Process imports for this organization
                    $importService->processOrganizationImports($organizationId);

                    $processedCount++;
                    $this->info("✓ Completed organization: {$organizationId}");

                } catch (\Exception $e) {
                    $errorCount++;
                    $this->error("✗ Failed to process organization {$organizationId}: " . $e->getMessage());
                    Log::error("Zapier import failed for organization", [
                        'organization_id' => $organizationId,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    // Continue with other organizations
                }
            }

            $this->info("\nImport process completed.");
            $this->info("Successfully processed: {$processedCount} organization(s)");
            if ($errorCount > 0) {
                $this->warn("Failed: {$errorCount} organization(s)");
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("Fatal error in Zapier import command: " . $e->getMessage());
            Log::error("Zapier import command fatal error", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return Command::FAILURE;
        }
    }

    /**
     * Get organizations to process
     */
    protected function getOrganizationsToProcess(): array
    {
        $organizationId = $this->option('organization');

        if ($organizationId) {
            // Process specific organization
            $settings = OrganizationZapierSetting::forOrganization($organizationId)->first();
            return $settings && !empty($settings->getEnabledModules()) ? [$organizationId] : [];
        }

        // Get all organizations with Zapier enabled
        $settings = OrganizationZapierSetting::withEnabledModules()->get();

        return $settings->pluck('organization_id')->unique()->toArray();
    }
}
