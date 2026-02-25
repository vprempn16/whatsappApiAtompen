<?php

namespace App\Models;

use App\Exceptions\PermissionDeniedException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

use Carbon\Carbon;

use App\Http\Resources\AddressResource;
use App\Http\Resources\SubtaskResource;
use App\Traits\HasComments;
use App\Traits\CascadesDeletes;
use App\Services\ListResponseService;
use App\Services\HookManager;
use App\Models\FieldModelManager;
use App\Services\CRM\RecordObject;
use App\Services\CRM\RelationshipService;
use App\Modules\Api\V1\GlobalSearchIndex\Models\GlobalSearchIndex;
use App\Modules\Api\V1\User\Models\User;
use App\Services\ModuleNumberingService;
use App\Models\OrganizationScope;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Models\Filter;
use App\Services\FilterService;

class AtomModel extends Model
{
	use CascadesDeletes;

	public $incrementing = false;
	protected $keyType = 'string';
	protected $customAttributes = [];
	protected $fieldModelManager;
	// SECURITY: Protect critical fields from mass assignment
	protected $guarded = ['id', 'organization_id', 'deleted', 'created_at', 'updated_at', 'created_by'];
	protected ?string $_viewType = null;

	protected function getFieldModelManager(): FieldModelManager
	{
		if (!$this->fieldModelManager) {
			$this->fieldModelManager = FieldModelManager::make($this->getModuleName(), $this->getViewType(), true);
		}

		return $this->fieldModelManager;
	}
	protected static function booted()
	{
		// Keep existing create/update handlers
		static::creating(function ($model) {
			if (!isset($model->id)) {
				$model->id = (string) Str::uuid();
			}
		});
		static::updating(function ($model) {
			if (!isset($model->id) && $model->is_converted == 1 && $this->getModuleName() === 'Lead') {
				throw \Illuminate\Validation\ValidationException::withMessages([
					'lead' => ['Lead is already converted and cannot be modified.']
				]);
			}
		});

		// Apply organization scoping globally (models that have organization_id will be filtered)
		try {
			static::addGlobalScope(new OrganizationScope());
		} catch (\Throwable $e) {
			// If anything fails during booting scope, log and continue to avoid breaking CLI tasks
			Log::warning('Failed to add OrganizationScope: ' . $e->getMessage());
		}
	}
	public function fill(array $attributes)
	{
		$fields = $this->getFieldModelManager()->getFields();
		$customFields = [];
		$standardFields = [];
		foreach ($attributes as $key => $value) {
			$field = $fields[$key] ?? null;
			if ($field) {
				$fieldName = $field->getFieldName();
				if ($field->isCustomField()) {
					$customFields[$fieldName] = $value;
				} else {
					// Preserve empty strings by setting attribute directly
					// This ensures empty strings are not converted to null
					$this->setAttribute($fieldName, $value === '' ? '' : $value);
					$standardFields[$fieldName] = $value === '' ? '' : $value;
				}
				Log::info("FILL - Field processed", [
					'field_name' => $fieldName,
					'field_value' => $value,
					'is_custom' => $field->isCustomField(),
				]);
			}
		}
		// Fill standard fields - empty strings should be preserved from setAttribute above
		parent::fill($standardFields);
		$this->customAttributes = $customFields;
		return $this;
	}
	public function save(array $options = [])
	{
		Log::info("SAVE - Start for module: {$this->getModuleName()}", ['attributes' => $this->getAttributes()]);
		$this->assignUuidIfNew();
		$this->assignNumbering();
		$this->assignDefaults();
		$this->validateBeforeSave();

		// Prevent updating a record that belongs to another organization
		$exempt = ['Organization', 'User', 'GlobalSearchIndex', 'ModuleNumberingDetail', 'Asset', 'AuditLog', 'ModuleRelationFields'];
		if ($this->exists && !in_array($this->getModuleName(), $exempt, true)) {
			$currentOrg = auth()->user()->organization_id ?? null;
			// If the model has an organization_id attribute and it doesn't match, block the update
			if (isset($this->organization_id) && $currentOrg && $this->organization_id !== $currentOrg) {
				throw new \Exception("This is not your organization’s record.");
			}
		}

		$hookData = $this->buildHookData();
		Log::info("SAVE - BeforeSave hook", ['hookData' => $hookData]);
		if ($this->stopSaveByHook($hookData)) {
			Log::warning("SAVE - Stopped by hook", ['hookData' => $hookData]);
			return false;
		}
		$saved = parent::save($options);
		if ($saved) {
			Log::info("SAVE - Core record saved", ['id' => $this->id]);
			$this->saveCustomValues();
			$this->loadCustomValues();
			Log::info("SAVE - Custom fields saved", ['customAttributes' => $this->customAttributes]);
			HookManager::executeHook($hookData['module'], 'afterSave', $hookData);
			Log::info("SAVE - AfterSave hook executed");

			// Trigger Workflows
			try {
				$workflowService = app(\App\Modules\Api\V1\Workflow\Services\WorkflowService::class);
				$event = ($hookData['is_update'] ?? false) ? 'updated' : 'created';

				// Ensure entity_id is set (important for dynamic ID resolution)
				if (empty($hookData['entity_id'])) {
					$hookData['entity_id'] = $this->id;
				}

				$workflowService->trigger($hookData['module'], $event, $hookData);
			} catch (\Throwable $e) {
				Log::error("Workflow Trigger Failed: " . $e->getMessage());
			}
		} else {
			Log::error("SAVE - Failed to save record", ['attributes' => $this->getAttributes()]);
		}
		return $saved;
	}
	private function assignUuidIfNew(): void
	{
		$table = $this->getTable();
		if (empty($this->id)) {
			$this->id = (string) Str::uuid();
			$this->created_at = now();
			Log::info("SAVE - Assigned new UUID: {$this->id}");
		} else if (!Schema::hasColumn($table, 'updated_at')) {
			$this->updated_at = now();
		}
	}

	private function assignNumbering(): void
	{
		$module = $this->getModuleName();

		// Prevent infinite recursion
		if ($module === 'ModuleNumberingDetail' || $module === 'Organization' || $module === 'GlobalSearchIndex' || $module === 'Asset') {
			return;
		}
		$table = $this->getTable();

		// Only assign numbering if the table has an 'identifier' column
		if (!Schema::hasColumn($table, 'identifier')) {
			return;
		}
		// Only generate a number if the identifier is not already set.
		if (!empty($this->identifier)) {
			return;
		}
		$orgId = $this->organization_id ?? (auth()->user()->organization_id ?? null);
		$number = ModuleNumberingService::generateNumber($module, $orgId);

		$this->identifier = $number;
	}

	private function assignDefaults(): void
	{
		if ($this->getModuleName() === 'Organization') {
			return;
		}
		$table = $this->getTable();
		$this->organization_id ??= auth()->user()->organization_id ?? null;
		if (!Schema::hasColumn($table, 'created_by')) {
			return;
		}
		$this->created_by ??= auth()->user()->id ?? null;
	}

	private function buildHookData(): array
	{
		$isNew = !$this->exists || empty($this->getOriginal());

		// Get standard attributes - use raw attributes to preserve empty strings
		$newValues = array_merge([], $this->attributes);

		// Merge custom attributes to ensure all fields are included
		foreach ($this->customAttributes as $dbField => $value) {
			$newValues[$dbField] = $value;
		}

		return [
			'new_values' => $newValues,
			'old_values' => $isNew ? [] : $this->getOriginal(),
			'entity_id' => $this->id ?? null,
			'module' => class_basename($this),
			'entity_name' => class_basename($this),
			'is_update' => !$isNew,
			'is_related' => false,
			'organization_id' => $this->organization_id ?? null,
			'more_info' => [
				'ip' => request()->ip(),
				'user_agent' => request()->userAgent(),
			],
			'related_entity_name' => null,
			'related_entity_id' => null,
		];
	}

	private function stopSaveByHook(array $hookData): bool
	{
		$result = HookManager::executeHook($hookData['module'], 'beforeSave', $hookData);

		if ($result['error']) {
			Log::warning("SAVE - Stopped by beforeSave hook", $result);
			return true;
		}

		return false;
	}
	protected function validateBeforeSave(): void
	{
		$fieldManager = $this->getFieldModelManager();
		$apiMap = $fieldManager->getFieldToApiMap(); // db_field => api_field

		$data = [];
		$onlyFields = [];

		/*
		 |--------------------------------------------------------------------------
		 | Build API payload from dirty standard fields
		 |--------------------------------------------------------------------------
		 */
		foreach ($this->getDirty() as $dbField => $value) {
			$apiField = $apiMap[$dbField] ?? $dbField;
			$data[$apiField] = $value;
			$onlyFields[] = $apiField;
		}

		/*
		 |--------------------------------------------------------------------------
		 | Add custom fields
		 |--------------------------------------------------------------------------
		 */
		foreach ($this->customAttributes as $dbField => $value) {
			$apiField = $apiMap[$dbField] ?? $dbField;
			$data[$apiField] = $value;
			$onlyFields[] = $apiField;
		}

		try {
			/*
			 |--------------------------------------------------------------------------
			 | VALIDATION (this MUTATES $data by reference)
			 |--------------------------------------------------------------------------
			 */
			if ($this->exists) {
				// update → only changed fields
				$fieldManager->validatePartial($data, array_values(array_unique($onlyFields)));
			} else {
				// create → validate all fields
				$fieldManager->validate($data);
			}

			/*
			 |--------------------------------------------------------------------------
			 | WRITE SANITIZED VALUES BACK INTO MODEL
			 |--------------------------------------------------------------------------
			 */
			foreach ($data as $apiField => $value) {
				// reverse map: api_field → db_field
				$dbField = array_search($apiField, $apiMap, true);

				if (!$dbField) {
					continue;
				}

				if (array_key_exists($dbField, $this->customAttributes)) {
					$this->customAttributes[$dbField] = $value;
				} else {
					$this->setAttribute($dbField, $value);
				}
			}

		} catch (ValidationException $e) {
			\Log::error(
				"VALIDATION FAILED for module {$this->getModuleName()}",
				['errors' => $e->errors()]
			);
			throw $e;
		}
	}

	public function saveCustomValues(): void
	{
		Log::info('CUSTOM SAVE - Start', ['customAttributes' => $this->customAttributes]);
		if (empty($this->customAttributes)) {
			Log::warning('CUSTOM SAVE - No custom attributes');
			return;
		}
		$module = $this->getModuleName();
		$customTable = 'l' . strtolower($module) . '_custom_values';
		$organization_id = auth()->user()->organization_id ?? null;
		if (!\Illuminate\Support\Facades\Schema::hasTable($customTable)) {
			Log::error("CUSTOM SAVE - Table {$customTable} does not exist");
			return;
		}
		$customFields = $this->getFieldModelManager()->getCustomFields();
		$fieldMap = collect($customFields)->mapWithKeys(fn($f) => [$f->getFieldName() => $f->getId()])->toArray();
		$now = now();
		$customInsertData = [];
		foreach ($this->customAttributes as $field => $value) {
			if (!isset($fieldMap[$field])) {
				Log::warning("CUSTOM SAVE - Skipping field '{$field}'");
				continue;
			}
			$customInsertData[] = [
				'id' => (string) \Illuminate\Support\Str::uuid(),
				'record_id' => $this->id,
				'organization_id' => $organization_id,
				'field_id' => $fieldMap[$field],
				'field_value' => $value,
				'created_at' => $now,
				'updated_at' => $now,
			];
		}
		if (!empty($customInsertData)) {
			\DB::table($customTable)->upsert(
				$customInsertData,
				['record_id', 'field_id', 'organization_id'],
				['field_value', 'updated_at']
			);

			Log::info("CUSTOM SAVE - Upserted custom fields", $customInsertData);
		}
	}

	public function loadCustomValues(): void
	{
		$module = $this->getModuleName();
		$customTable = 'l' . strtolower($module) . '_custom_values';

		if (!Schema::hasTable($customTable)) {
			return;
		}

		$fieldMap = $this->getCustomFieldMap();

		$query = DB::table($customTable)->where('record_id', $this->getKey());

		if (Schema::hasColumn($customTable, 'organization_id') && $this->organization_id) {
			$query->where('organization_id', $this->organization_id);
		}

		$customRows = $query->get();
		if ($customRows->count()) {
			Log::info("LOAD CUSTOM FIELDS - Found " . $customRows->count() . " custom fields for record ID: {$this->id}");
		}


		foreach ($customRows as $row) {
			if (isset($fieldMap[$row->field_id])) {
				$this->customAttributes[$fieldMap[$row->field_id]] = $row->field_value;
			}
		}

	}

	protected function getCustomFieldMap(): array
	{
		$customFields = $this->getFieldModelManager()->getCustomFields();

		return collect($customFields)
			->mapWithKeys(fn($f) => [$f->getId() => $f->getFieldName()])
			->toArray();
	}

	public function getFields()
	{
		$fields = $this->getFieldModelManager()->getFields();
		return $fields;
	}
	public function getApiFormFields()
	{
		$module = $this->getModuleName();
		$fieldManager = FieldModelManager::make($module, $this->_viewType ?? 'DetailView', true);
		$fields = $fieldManager->getApiFormFields();
		return $fields;
	}

	protected function getApiToFieldMap(): array
	{
		$fields = $this->getFieldModelManager()->getFields();
		return collect($fields)->mapWithKeys(fn($f) => [$f->getAPIName() => $f->getFieldName()])->toArray();
	}

	public function transformToApiFormat(int $limit = 20, int $offset = 0): array
	{
		if (empty($this->customAttributes)) {
			$this->loadCustomValues();
		}

		if (!$this->exists && empty($this->id)) {
			return [];
		}
		$fieldManager = FieldModelManager::make($this->getModuleName(), $this->_viewType ?? 'DetailView', true);
		$fields = $fieldManager->getFields();
		$fieldMap = collect($fields)->mapWithKeys(fn($f) => [$f->getFieldName() => $f])->toArray();
		$output = [];
		foreach ($fieldManager->getApiFormFields() as $fieldArr) {
			$apiField = $fieldArr['fieldname'];
			$field = $fields[$apiField] ?? null;
			if ($field) {

				$dbField = $field->getFieldName();
				$ftype = strtolower($field->getFieldType());
				$value = $this->$dbField ?? null;

				if ($dbField === 'assigned_to' || $dbField === 'created_by') {
					$user = User::find((string) $this->$dbField);
					$output[$apiField] = $this->$dbField;
					continue;
				}

				if ($dbField === 'contact_id' || $dbField === 'converted_contact_id' || $dbField === 'customer_id') {
					try {
						$contact = RecordObject::make('Contact', $this->$dbField, [], 'EditView');
						if ($contact && ($contact->first_name || $contact->last_name)) {
							$output[$apiField . '_label'] = trim($contact->first_name . ' ' . $contact->last_name);
						} else {
							$output[$apiField . '_label'] = 'N/A';
						}
					} catch (ModelNotFoundException $e) {
						// Contact doesn't exist - set label to N/A
						$output[$apiField . '_label'] = 'N/A';
					} catch (\Exception $e) {
						// Any other error - log and set label to N/A
						Log::warning("Failed to load contact for {$dbField}: " . $e->getMessage());
						$output[$apiField . '_label'] = 'N/A';
					}
					$output[$apiField] = $this->$dbField;
					continue;
				}
				if ($this->is_converted_from_lead == 1 && $dbField == 'converted_lead_id') {
					$lead = RecordObject::make('Lead', $this->$dbField, [], 'DetailView');
					if ($lead && ($lead->first_name || $lead->last_name)) {
						$output[$apiField . '_label'] = trim($lead->first_name . ' ' . $lead->last_name);
					} else {
						$output[$apiField . '_label'] = 'N/A';
					}
					continue;
				}
				switch ($ftype) {
					case 'decimal':
					case 'integer':
						$output[$apiField] = is_null($value) ? null : (int) $value;
						break;
					case 'boolean':
						$output[$apiField] = (bool) $value;
						break;
					case 'picklist':
					case 'relationpicklist':
						$output[$apiField] = $value;
						break;
					case 'timestamp':
					case 'datetime':
						$output[$apiField] = !empty($value) ? \Carbon\Carbon::parse($value)->format('d M Y') : null;
						break;
					case 'phone':
					case 'email':
						$output[$apiField] = $value;
						break;
					case 'password':
						$output[$apiField] = $value ? '******' : null;
						break;
					case 'uuid':
					case 'string':
					case 'text':
					case 'textarea':
					default:
						$output[$apiField] = $value;
						break;
				}
			}
		}

		// Ensure every record includes its id
		if (!isset($output['id'])) {
			$output['id'] = $this->id;
		}

		// Merge custom attributes
		$fieldManager = $this->getFieldModelManager();
		$apiMap = $fieldManager->getFieldToApiMap(); // db => api

		foreach ($this->customAttributes as $dbField => $value) {
			$output[$apiMap[$dbField] ?? $dbField] = $value;
		}

		return $output;
	}

	/**
	 * Example helper for picklist labels
	 */
	protected function getPicklistLabel(string $field, $value): ?string
	{
		$map = [
			// your picklist value => label mapping here
			'active' => 'Active',
			'inactive' => 'Inactive',
		];
		return $map[$value] ?? $value;
	}

	public function formatToApi(): array
	{
		$fields = $this->getFieldModelManager()->getFields();
		$apiMap = collect($fields)
			->filter(fn($f) => $f->getDisplaytype() == 1) // only keep displaytype = 1
			->mapWithKeys(fn($f) => [$f->getFieldName() => $f->getAPIName()])
			->toArray();

		$data = $this->toArray();
		$output = [];

		foreach ($data as $key => $value) {
			$apiKey = $apiMap[$key] ?? $key;
			$output[$apiKey] = $value;
		}

		foreach ($this->customAttributes as $key => $value) {
			$apiKey = $apiMap[$key] ?? $key;
			$output[$apiKey] = $value;
		}

		return $output;
	}
	public function setViewType(string $viewType): void
	{
		$this->_viewType = $viewType;
		$this->fieldModelManager = null;
	}

	public function getViewType(): string
	{
		return $this->_viewType ?? 'DetailView';
	}

	public function __get($key)
	{
		if (is_string($key) && str_starts_with($key, '_') && property_exists($this, $key)) {
			return $this->$key;
		}
		return $this->customAttributes[$key] ?? parent::__get($key);
	}

	public function __set($key, $value)
	{
		if (is_string($key) && str_starts_with($key, '_') && property_exists($this, $key)) {
			$this->$key = $value;
			if ($key === '_viewType') {
				$this->fieldModelManager = null;
			}
			return;
		}
		if (array_key_exists($key, $this->getCustomFieldMap())) {
			$this->customAttributes[$key] = $value;
			return;
		}
		parent::__set($key, $value);
	}
	public function getModuleName(): string
	{
		return class_basename(static::class);
	}
	public function scopeFilterByRequest($query, $value, $module, $organizationId = null)
	{
		$query->where('module_name', $module);

		if (!empty($value)) {
			// Security: Sanitize search value to prevent SQL injection
			$searchValue = '%' . addcslashes($value, '%_\\') . '%';
			$query->where(function ($q) use ($searchValue) {
				$q->where('label', 'like', $searchValue)
					->orWhere('search_text', 'like', $searchValue);
			});
		}

		if (!empty($organizationId)) {
			$query->where('organization_id', $organizationId);
		}

		return $query;
	}
	public function getRelatedRecords(): array
	{
		$relatedRecords = [];

		// Load relationships using the centralized RelationshipService
		$relatedRecords = RelationshipService::loadRelatedRecords($this);

		// Handle comments separately (if module supports comments)
		if (method_exists($this, 'getComments')) {
			$relatedRecords['comments'] = $this->getRelatedComments();
		}

		// Handle nested relationships (e.g., checklists within invoice items)
		// This is a special case that can be extended if needed
		$relatedRecords = $this->loadNestedRelationships($relatedRecords);

		return $relatedRecords;
	}

	/**
	 * Load nested relationships (e.g., checklists within invoice items)
	 * This can be extended or moved to config if needed
	 */
	protected function loadNestedRelationships(array $relatedRecords): array
	{
		$module = $this->getModuleName();

		// Special handling for Invoice -> InvoiceItem -> Checklist
		if ($module === 'Invoice' && isset($relatedRecords['invoice_items'])) {
			foreach ($relatedRecords['invoice_items'] as &$item) {
				if (isset($item['id'])) {
					$itemId = $item['id'];
					$orgId = auth()->user()->organization_id ?? null;

					$checklistIds = DB::table('checklists')
						->where('record_id', $itemId)
						->when($orgId, function ($q) use ($orgId) {
							$q->where('organization_id', $orgId);
						})
						->pluck('id');

					$checklists = [];
					foreach ($checklistIds as $checklistId) {
						try {
							$checklists[] = RecordObject::make('Checklist', $checklistId)
								->transformToApiFormat();
						} catch (\Exception $e) {
							continue;
						}
					}

					$item['checklists'] = $checklists;
				}
			}
		}

		return $relatedRecords;
	}

	protected function getRelatedComments(): array
	{
		$id = $this->id;
		$modulename = $this->getModuleName();

		return $this->getComments($modulename, $id) ?? [];
	}
	protected function getRelatedSubtasks(): array
	{
		$id = $this->id;

		$subtasks = DB::table('tasks')
			->where('related_recordid', $id)
			->get();

		return (array) $subtasks;
	}
	public static function getList(array $filters = [], int $perPage = 20, int $page = 1)
	{
		$instance = new static;
		$query = $instance->newQuery();
		$moduleName = $instance->getModuleName();

		// Find the default filter (prefer org-specific, then global)
		$defaultFilter = Filter::where('module_name', $moduleName)
			->where('is_default', 1)
			->where('deleted', 0)
			->where(function ($q) {
				$orgId = auth()->user()->organization_id;
				$q->where('organization_id', $orgId)->orWhereNull('organization_id');
			})
			->orderByRaw('organization_id IS NULL ASC')
			->first();

		if (!$defaultFilter) {
			return ['filter_id' => null, 'details' => [], 'meta' => [], 'links' => []];
			//throw new ModelNotFoundException("No default filter found for the {$moduleName} module.");
		}

		if (Schema::hasColumn($instance->getTable(), 'deleted')) {
			$query->where('deleted', 0);
		}
		if (Schema::hasColumn($instance->getTable(), 'organization_id')) {
			$query->where('organization_id', auth()->user()->organization_id);
		}

		// Apply the conditions from the default filter
		FilterService::applyFilter($query, $defaultFilter->id, $moduleName);

		$query->orderBy('created_at', 'desc');

		$paginator = $query->paginate($perPage, ['*'], 'page', $page);

		$list = $paginator->getCollection()->map(function ($record) {
			return $record->transformToApiFormat();
		});

		return [
			"filter_id" => $defaultFilter->id,
			'details' => $list,
			'meta' => [
				'current_page' => $paginator->currentPage(),
				'last_page' => $paginator->lastPage(),
				'per_page' => $paginator->perPage(),
				'total' => $paginator->total(),
				'filter_id' => $defaultFilter->id,
			],
			'links' => [
				'first' => $paginator->url(1),
				'last' => $paginator->url($paginator->lastPage()),
				'prev' => $paginator->previousPageUrl(),
				'next' => $paginator->nextPageUrl(),
			]
		];
	}
	public function deleteRecord(): bool
	{
		Log::info("DELETE - Start for record ID: {$this->id} in module {$this->getModuleName()}");
		\DB::beginTransaction();
		try {
			// Prevent deleting a record that belongs to another organization
			$exempt = ['Organization', 'User', 'GlobalSearchIndex', 'ModuleNumberingDetail', 'Asset', 'AuditLog', 'ModuleRelationFields'];
			if (!in_array($this->getModuleName(), $exempt, true)) {
				$currentOrg = auth()->user()->organization_id ?? null;
				if (isset($this->organization_id) && $currentOrg && $this->organization_id !== $currentOrg) {
					\DB::rollBack();
					throw new \Exception("This is not your organization’s record.");
				}
			}
			$permissionService = new \App\Services\PermissionService(auth()->user());
			$moduleAction = 'delete';
			if (!$permissionService->hasPermission($this->getModuleName(), $moduleAction)) {
				throw new PermissionDeniedException(
					"Unauthorized: No {$moduleAction} permission for module {$this->getModuleName()}"
				);
			}
			$now = now();
			$oldValues = $this->getOriginal();
			$hookData = [
				'new_values' => [],
				'old_values' => $oldValues,
				'entity_id' => $this->id,
				'module' => class_basename($this),
				'entity_name' => class_basename($this),
				'is_update' => false,
				'is_related' => false,
				'organization_id' => $this->organization_id ?? null,
				'more_info' => [
					'ip' => request()->ip(),
					'user_agent' => request()->userAgent(),
				],
				'related_entity_name' => null,
				'related_entity_id' => null,
				'is_delete' => true,
			];
			Log::info("DELETE - BeforeDelete hook", ['hookData' => $hookData]);
			$check = HookManager::executeHook($hookData['module'], 'beforeDelete', $hookData);
			if ($check['error'] ?? false) {
				Log::warning("DELETE stopped by beforeDelete hook", $check);
				\DB::rollBack();
				return false;
			}
			// Cascade soft-delete to related records (hasMany, hasOne, morphMany) before soft-deleting self
			$this->cascadeDeleteToDependents(false);
			if (\Illuminate\Support\Facades\Schema::hasColumn($this->getTable(), 'deleted')) {
				$this->deleted = 1;
			}
			$this->updated_at = $now;
			parent::save();

			// Trigger Workflow for Deleted
			try {
				$workflowService = app(\App\Modules\Api\V1\Workflow\Services\WorkflowService::class);
				$workflowService->trigger($hookData['module'], 'deleted', $hookData);
			} catch (\Throwable $e) {
				Log::error("Workflow Delete Trigger Failed: " . $e->getMessage());
			}

			$customTable = strtolower($this->getModuleName()) . '_custom_values';
			if (\Illuminate\Support\Facades\Schema::hasTable($customTable) && \Illuminate\Support\Facades\Schema::hasColumn($customTable, 'deleted')) {
				\DB::table($customTable)
					->where('record_id', $this->id)
					->update(['deleted' => 1, 'updated_at' => $now]);
			}
			if (\Illuminate\Support\Facades\Schema::hasTable('address_rel') && \Illuminate\Support\Facades\Schema::hasColumn('address_rel', 'deleted')) {
				\DB::table('address_rel')
					->where('parent_id', $this->id)
					->where('parent_module', $this->getModuleName())
					->update(['deleted' => 1, 'updated_at' => $now]);
			}
			\DB::commit();
			Log::info("DELETE - Record {$this->id} soft deleted.");
			HookManager::executeHook($hookData['module'], 'afterDelete', $hookData);
			return true;
		} catch (\Exception $e) {
			\DB::rollBack();
			Log::error("DELETE - Failed: {$e->getMessage()}");
			throw $e;
		}
	}
	public static function getEmailAddress(string $module, string $recordId)
	{
		try {
			if (!$module || !$recordId) {
				return ['values' => ['recipients' => []]];
			}

			$fieldManager = FieldModelManager::make($module, 'DetailView', true);
			$fields = $fieldManager->getFields();
			$emailFields = [];

			foreach ($fields as $field) {
				if (strcasecmp($field->getFieldType(), 'email') === 0) {
					$emailFields[] = $field;
				}
			}

			if (empty($emailFields)) {
				return ['values' => ['recipients' => []]];
			}

			// Get Table Name from first field (all fields in module share table)
			$tableName = $emailFields[0]->getTableName();

			$record = DB::table($tableName)
				->where('id', $recordId)
				->first();

			if (!$record) {
				return ['values' => ['recipients' => []]];
			}

			$recipients = [];
			foreach ($emailFields as $field) {
				$fieldName = $field->getFieldName();
				if (!empty($record->$fieldName)) {
					$recipients[] = [
						'module_name' => $module,
						'recordId' => $recordId,
						'field' => $field->getAPIName(), // Use API name for frontend
						'value' => $record->$fieldName
					];
				}
			}

			return [
				'values' => [
					'recipients' => $recipients
				]
			];

		} catch (\Exception $e) {
			\Log::error("Error fetching email fields: " . $e->getMessage());
			return ['values' => ['recipients' => []]];
		}
	}
}
