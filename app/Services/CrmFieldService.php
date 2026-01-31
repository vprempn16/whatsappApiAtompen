<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class CrmFieldService
{
    /**
     * Fetch CRM fields for a module, with organization-specific label overrides.
     */
    public function getFieldsWithOverrides($modulename, $organizationId = null)
    {
        return DB::table('crm_fields')
            ->leftJoin('crm_default_field_definitions as overrides', function ($join) use ($organizationId, $modulename) {
                $join->on('crm_fields.modulename', '=', 'overrides.modulename')
                    ->on('crm_fields.fieldname', '=', 'overrides.fieldname')
                    ->where('overrides.organization_id', '=', $organizationId);
            })
            ->where('crm_fields.modulename', $modulename)
            ->where('crm_fields.organization_id', 'default')
            ->select([
                'crm_fields.*',
                DB::raw('COALESCE(overrides.fieldlabel, crm_fields.fieldlabel) as fieldlabel'),
                DB::raw('COALESCE(overrides.mandatory, crm_fields.mandatory) as mandatory'),
                DB::raw('COALESCE(overrides.seq, crm_fields.seq) as seq'),
            ])
            ->get();
    }
}
