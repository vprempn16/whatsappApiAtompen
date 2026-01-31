<?php

namespace App\Modules\Api\V1\RelatedRecords\Controllers;

use App\Http\Controllers\ApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use App\Modules\Api\V1\RelatedRecords\Models\ModuleRelationField;
use App\Services\CRM\RecordObject;
use App\Services\PermissionService;

class RelatedRecords extends ApiController
{
	public function index(Request $request, string $module, string $id, string $relatedModule)
	{
		try {
			// 1) Find relation definition
			$relation = ModuleRelationField::where('related_module', $module)
				->where('modulename', $relatedModule)
				->where('deleted', 0)
				->first();

			if (!$relation) {
				return $this->error("Relation between {$module} and {$relatedModule} not found.");
			}

			// 2) Resolve parent & related model classes
			$orgId = auth()->user()->organization_id;
			$user = auth()->user();
			
			// Check permission to view parent module
			$permissionService = new PermissionService($user);
			if (!$user->is_admin && !$permissionService->hasPermission($module, 'view')) {
				return $this->error("Unauthorized: No view permission for module {$module}");
			}
			
			// Check permission to view related module
			if (!$user->is_admin && !$permissionService->hasPermission($relatedModule, 'view')) {
				return $this->error("Unauthorized: No view permission for module {$relatedModule}");
			}
			
			$parent = RecordObject::make($module, $id);
			if (!$parent) {
				return $this->error("{$module} with id {$id} not found.");
			}
			
			// Verify parent belongs to user's organization
			if (isset($parent->organization_id) && $parent->organization_id !== $orgId) {
				return $this->error("Unauthorized: Record does not belong to your organization.");
			}

			$relatedClass = "\\App\\Modules\\Api\\V1\\{$relatedModule}\\Models\\{$relatedModule}";
			if (!class_exists($relatedClass)) {
				return $this->error("Related module {$relatedModule} not found.");
			}

			$relatedInstance = new $relatedClass;

			// 3) Try to resolve DB field name from field_id
			$dbFieldName = null;
			
			// First, check if field_id is actually a field name (string) vs UUID
			$fieldIdValue = $relation->field_id;
			
			// Try to find field by ID (UUID)
			$fields = $relatedInstance->getFields();
			foreach ($fields as $f) {
				if ((string)$f->getId() === (string)$fieldIdValue) {
					$dbFieldName = $f->getFieldName();
					break;
				}
				// Also check if field_id is the field name itself
				if ($f->getFieldName() === $fieldIdValue || $f->getAPIName() === $fieldIdValue) {
					$dbFieldName = $f->getFieldName();
					break;
				}
			}

			// 4) Fallback guesses if not found
			if (!$dbFieldName) {
				// If field_id looks like a field name (contains underscore or camelCase), try it directly
				if (strpos($fieldIdValue, '_') !== false || ctype_lower(substr($fieldIdValue, 0, 1)) || preg_match('/[a-z]/', $fieldIdValue)) {
					if (Schema::hasColumn($relatedInstance->getTable(), $fieldIdValue)) {
						$dbFieldName = $fieldIdValue;
					}
				}
				
				// Standard fallback guesses
				if (!$dbFieldName) {
					$guesses = [
						strtolower($module) . '_id',
						Str::snake($module) . '_id',
						'parent_id',
						'customer_id', // Common field for Contact relationship
						'contact_id',  // Alternative for Contact relationship
					];

					foreach ($guesses as $g) {
						if (Schema::hasColumn($relatedInstance->getTable(), $g)) {
							$dbFieldName = $g;
							break;
						}
					}
				}
			}

			if (!$dbFieldName) {
				return $this->error("No valid relation field found for {$relatedModule} → {$module}.");
			}

			// 5) Query related records
			$query = $relatedClass::where($dbFieldName, $id);
			if (Schema::hasColumn($relatedInstance->getTable(), 'deleted')) {
				$query->where('deleted', 0);
			}
			$query->where('organization_id', $orgId);

			$records = $query->get();
			// 6) Transform to API format
			$data = [];
			foreach ($records as $r) {
				$obj = RecordObject::make($relatedModule, $r->id);
				$data[] = $obj->transformToApiFormat();
			}

			return $this->success([
				'relatedRecords' => $data,
				'meta' => [
					'parentModule'   => $module,
					'parentId'       => $id,
					'relatedModule'  => $relatedModule,
					'relatedField'   => $dbFieldName,
					'count'          => count($data),
				]
			]);
		} catch (\Exception $e) {
			return $this->error($e->getMessage());
		}
	}
}
