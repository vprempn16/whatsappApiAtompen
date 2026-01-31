<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Filter;
use App\Modules\Api\V1\User\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class CreateDefaultFilters extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'filters:create-default {--module=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create default filters for Quotation and Invoice modules with specified columns';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $module = $this->option('module');
        
        // Set up user authentication
        $user = User::first();
        if (!$user) {
            $this->error('❌ No user found. Please create a user first.');
            return 1;
        }
        
        Auth::loginUsingId($user->id);
        $orgId = $user->organization_id;
        
        $this->info("✅ Authenticated as: {$user->first_name} {$user->last_name} (Org ID: {$orgId})\n");
        
        // Define modules to process
        $modules = $module ? [$module] : ['Quotation', 'Invoice'];
        
        foreach ($modules as $moduleName) {
            $this->info("📋 Creating default filter for module: {$moduleName}");
            
            // Check if default filter already exists
            $existing = Filter::where('module_name', $moduleName)
                ->where('organization_id', $orgId)
                ->where('is_default', 1)
                ->where('deleted', 0)
                ->first();
            
            if ($existing) {
                $this->warn("⚠️  Default filter already exists for {$moduleName} (ID: {$existing->id})");
                // Remove default from existing and delete it
                $existing->is_default = 0;
                $existing->deleted = 1;
                $existing->save();
            }
            
            // Define columns for each module
            $columns = $this->getDefaultColumns($moduleName);
            
            if (empty($columns)) {
                $this->error("❌ No columns defined for module: {$moduleName}");
                continue;
            }
            
            try {
                // Create filter with exact structure as requested
                $filter = Filter::create([
                    'id' => (string) Str::uuid(),
                    'name' => "Default {$moduleName} Filter",
                    'description' => "System default filter for {$moduleName} list view",
                    'module_name' => $moduleName,
                    'organization_id' => $orgId,
                    'created_by' => $user->id,
                    'is_shared' => true,
                    'is_default' => true,
                    'header_details' => [
                        'columns' => $columns
                    ],
                ]);
                
                // Ensure only one default filter per module
                $filter->setAsDefault();
                
                $this->info("✅ Created default filter for {$moduleName}");
                $this->line("   ID: {$filter->id}");
                $this->line("   Name: {$filter->name}");
                $this->line("   Columns: " . implode(', ', $columns));
                $this->line("");
                
            } catch (\Exception $e) {
                $this->error("❌ Failed to create filter for {$moduleName}: {$e->getMessage()}");
                continue;
            }
        }
        
        $this->info("🎉 Default filter creation completed!");
        return 0;
    }
    
    /**
     * Get default columns for a module
     */
    private function getDefaultColumns(string $module): array
    {
        $columns = [
            'Quotation' => [
                'quotationNumber',
                'customerId',
                'quotationDate',
                'validUntil',
                'quotationStatus',
                'totalAmount',
                'subtotal',
                'taxAmount',
                'notes',
            ],
            'Invoice' => [
                'invoiceNumber',
                'customerId',
                'invoiceDate',
                'dueDate',
                'invoiceStatus',
                'totalAmount',
                'amountPaid',
                'balanceDue',
                'subtotal',
                'taxAmount',
                'quotationId',
            ],
        ];
        
        return $columns[$module] ?? [];
    }
}
