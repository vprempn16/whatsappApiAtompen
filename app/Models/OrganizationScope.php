<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Schema;

class OrganizationScope implements Scope
{
	/**
	 * Modules to exempt from organization scoping
	 * (class_basename of the model)
	 */
	protected array $exemptModules = [
		'Organization',
		'User',
		'GlobalSearchIndex',
		'ModuleNumberingDetail',
		'Asset',
		'AuditLog',
		'ModuleRelationFields',
	];

	public function apply(Builder $builder, Model $model)
	{
		$table = $model->getTable();

		// Only apply if the table actually has organization_id
		if (!Schema::hasColumn($table, 'organization_id')) {
			return;
		}

		// If no authenticated user, skip scoping (allow CLI / system jobs)
		$user = auth()->user();
		if (!$user) {
			return;
		}

		// Exempt certain models
		if (in_array(class_basename($model), $this->exemptModules, true)) {
			return;
		}

		$builder->where($table . '.organization_id', $user->organization_id);
	}

	// remove() not required for our use-case; left intentionally empty
}
