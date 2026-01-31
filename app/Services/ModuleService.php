<?php

namespace App\Services;

use App\Models\PortalModule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ModuleService
{
    /**
     * Frontend-visible modules only
     */
    private const FRONTEND_MODULES = [
        'Contact',
        'Lead',
        'Product',
        'Activity',
        'Quotation',
        'Invoice',
        'Payment',
        'Asset',
    ];

    /**
     * Map API route module names (plural) to internal CRM module names (singular).
     */
    public static function resolveName(string $module): string
    {
        $map = [
            'Contacts'      => 'Contact',
            'Leads'         => 'Lead',
            'Accounts'      => 'Organization',
            'Products'      => 'Product',
            'Activities'    => 'Activity',
            'Invoices'      => 'Invoice',
            'Quotations'    => 'Quotation',
            'Payments'      => 'Payment',
            'Assets'        => 'Asset',
            'Organizations' => 'Organization',
            'Users'         => 'User',
        ];

        return $map[$module] ?? $module;
    }

    /**
     * Return active entity modules only
     * Used for profile permissions, fields, UI
     */
    public static function getEntityModules(): array
    {
        self::syncPortalModules();

        return PortalModule::where('is_entity', 1)
            ->where('status', 'Active')
            ->whereIn('modulename', self::FRONTEND_MODULES)
            ->orderBy('sort_order')
            ->orderBy('modulename')
            ->pluck('modulename')
            ->toArray();
    }

    /**
     * Ensure portal_module entries exist for core modules.
     */
    private static function syncPortalModules(): void
    {
        $seedFile = app_path('Models/AtomPen/PortalModule.php');
        if (!file_exists($seedFile)) {
            return;
        }

        $modules = include $seedFile;
        if (!is_array($modules)) {
            return;
        }

        foreach ($modules as $module) {
            if (empty($module['modulename'])) {
                continue;
            }

            $exists = DB::table('portal_module')
                ->where('modulename', $module['modulename'])
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('portal_module')->insert([
                'id' => $module['id'] ?? (string) Str::uuid(),
                'modulename' => $module['modulename'],
                'modulelabel' => $module['modulelabel'] ?? $module['modulename'],
                'is_entity' => $module['is_entity'] ?? 1,
                'status' => $module['status'] ?? 'Active',
                'sort_order' => $module['sort_order'] ?? 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
