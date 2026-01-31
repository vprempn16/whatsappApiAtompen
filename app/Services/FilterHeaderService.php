<?php

namespace App\Services;

use App\Models\Filter;
use App\Models\FieldModelManager;

class FilterHeaderService
{
    public static function resolve(
    string $module,
    $user,
    ?string $filterId = null
): array {

    $headerDetails = null;

    // 1️⃣ Filter headers
    if ($filterId) {
        $filter = Filter::where('id', $filterId)
            ->where('module_name', $module)
            ->where('organization_id', $user->organization_id)
            ->where('deleted', false)
            ->first();

        if ($filter && !empty($filter->header_details)) {
            $headerDetails = $filter->header_details;
        }
    }

    // 2️⃣ Default filter fallback
    if (!$headerDetails) {
        $defaultFilter = Filter::where('module_name', $module)
            ->where('organization_id', $user->organization_id)
            ->where('is_default', true)
            ->where('deleted', false)
            ->first();

        if ($defaultFilter && !empty($defaultFilter->header_details)) {
            $headerDetails = $defaultFilter->header_details;
        }
    }

    // 3️⃣ Load ListView fields (FINAL structure)
    $fieldManager = FieldModelManager::make($module, 'ListView', true);
    $fields = collect($fieldManager->getApiFormFields());

    // 4️⃣ Apply column filtering
    if (!empty($headerDetails['columns'])) {
        $fields = $fields
            ->filter(fn ($f) =>
                in_array($f['fieldname'], $headerDetails['columns'], true)
            )
            ->values();
    }

    return ['fields' => $fields];
}
}