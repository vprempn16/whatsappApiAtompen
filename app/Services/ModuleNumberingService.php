<?php

namespace App\Services;

use App\Modules\Api\V1\ModuleNumberingDetail\Models\ModuleNumberingDetail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ModuleNumberingService
{
	public static function generateNumber(string $module, string $org_id): string
	{
		return DB::transaction(function () use ($module, $org_id) {
			// Lock the row so parallel requests don't generate duplicates
			$entry = ModuleNumberingDetail::where('module_name', $module)
				->where('organization_id', $org_id)
				->lockForUpdate()
				->first();

			if (!$entry) {
				$prefix = strtoupper(substr($module, 0, 3)); // Example: Job => JOB
				$entry = ModuleNumberingDetail::create([
					'id' => (string) Str::uuid(),
					'organization_id'         => $org_id,
					'module_name'    => $module,
					'prefix'         => $prefix,
					'initial_suffix' => 1,
					'current_suffix' => 1,
				]);
			}

			$number = $entry->prefix . str_pad($entry->current_suffix, 4, '0', STR_PAD_LEFT);

			// Increment for next time
			$entry->increment('current_suffix');

			return $number;
		});
	}
}
