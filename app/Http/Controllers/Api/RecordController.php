<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Services\CRM\RecordObject;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use App\Models\Filter;
use App\Services\FilterService;
use App\Services\PermissionService;
class RecordController extends ApiController
{
    public function store(Request $request, string $module, string $id)
    {
        try {
            $isNew = ($id === 'new');
            $id = $isNew ? null : $id;

            $data = $request->input('data.values', []) ?: $request->all();
            $relatedRecords = $request->input('data.relatedRecords', []);

            if (empty($data)) {
                return $this->error('No data received for saving');
            }

            $model = RecordObject::make($module, $id, $data, 'EditView');

            if (!empty($relatedRecords)) {
                $model = RecordObject::saveWithRelations($model, $relatedRecords);
            } else {
                $model->save();
            }

            return $this->success(['id' => $model->id]);
        } catch (ValidationException $e) {
            return $this->error($e->getMessage());
        } catch (\Exception $e) {
            \Log::error("Record store failed for {$module}", ['error' => $e->getMessage()]);
            return $this->error('Failed to save record. Please try again.');
        }
    }

    public function show(string $module, string $id, Request $request)
    {
        try {
            if ($id === 'new') {
                $fieldManager = \App\Models\FieldModelManager::make($module, 'EditView' , true);
                return $this->success([
                    'fields' => $fieldManager->getApiFormFields(),
                ]);
            }

            $viewType = 'DetailView';
            $record = RecordObject::make($module, $id, [], $viewType);
            $fieldManager = \App\Models\FieldModelManager::make($module, $viewType , true);

            return $this->success([
                'fields' => $fieldManager->getApiFormFields(),
                'values' => $record->transformToApiFormat(),
                'relatedRecords' => $record->getRelatedRecords(),
            ]);
        } catch (\Exception $e) {
            \Log::error("Record show failed for {$module}/{$id}", ['error' => $e->getMessage()]);
            return $this->error($e->getMessage());
        }
    }

    public function edit(string $module, string $id)
    {
        try {
            $viewType = 'EditView';
            $fieldManager = \App\Models\FieldModelManager::make($module, $viewType,true);
            $fields = $fieldManager->getApiFormFields();
           
            if ($id === 'new') {
                return $this->success(['fields' => $fields]);
            }

            $record = RecordObject::make($module, $id, [], $viewType);

            return $this->success([
                'fields' => $fields,
                'values' => $record->transformToApiFormat(),
                'relatedRecords' => $record->getRelatedRecords(),
            ]);
        } catch (\Exception $e) {
            \Log::error("Record edit failed for {$module}/{$id}", ['error' => $e->getMessage()]);
            return $this->error($e->getMessage());
        }
    }

    public function createForm(string $module)
    {
        try {
            $fieldManager = \App\Models\FieldModelManager::make($module, 'CreateView', true);
            return $this->success([
                'fields' => $fieldManager->getApiFormFields(),
            ]);
        } catch (\Exception $e) {
            \Log::error("Record createForm failed for {$module}", ['error' => $e->getMessage()]);
            return $this->error('Failed to fetch create fields.');
        }
    }
    /**
 * Get fields based on selected filter columns (header_details)
 * GET /api/v1/{module}/filters/{filterId}/fields
 */
/**
 * Header fields for ListView (Filter based / Default)
 * GET /api/v1/{module}/headers/{filterId}
 */
public function filterHeaderFields(string $module, string $filterId)
{
    try {
        $user = auth()->user();

        /**
         * --------------------------------------------------
         * Resolve Filter
         * --------------------------------------------------
         */
        if ($filterId === 'default') {

            $filter = \App\Models\Filter::query()
                ->where('module_name', $module)
                ->where('organization_id', $user->organization_id)
                ->where('is_default', 1)
                ->where('deleted', 0)
                ->first();

            if (!$filter) {
                return $this->error(
                    'No default filter found. Please create a default filter or choose a filter.'
                );
            }

        } else {

            $filter = \App\Models\Filter::find($filterId);

            if (!$filter || $filter->deleted) {
                return $this->error('Filter not found');
            }

            if ($filter->organization_id !== $user->organization_id) {
                return $this->error('Unauthorized access');
            }
        }

        /**
         * --------------------------------------------------
         * Resolve Selected Columns
         * --------------------------------------------------
         */
        $selectedColumns = $filter->header_details['columns'] ?? [];

        if (empty($selectedColumns)) {
            return $this->error(
                'No header columns defined for this filter'
            );
        }

        /**
         * --------------------------------------------------
         * Load ListView Fields (Permission aware)
         * --------------------------------------------------
         */
        $fieldManager = \App\Models\FieldModelManager::make(
            $module,
            'ListView',
            true
        );

        $allFields = $fieldManager->getApiFormFields();

        /**
         * --------------------------------------------------
         * Filter Only Selected Columns
         * --------------------------------------------------
         */
        $filteredFields = array_values(
            array_filter($allFields, function ($field) use ($selectedColumns) {
                return in_array($field['fieldname'], $selectedColumns, true);
            })
        );

        return $this->success([
            'filter_id' => $filter->id,
            'is_default' => (bool) $filter->is_default,
            'fields' => $filteredFields
        ]);

    } catch (\Exception $e) {
        return $this->error(
            'Failed to load header fields: ' . $e->getMessage()
        );
    }
}

    /**
     * Header fields for ListView
     */
    public function headerfields(string $module)
    {
        try {
            $fieldManager = \App\Models\FieldModelManager::make($module, 'ListView' , true);
            return $this->success([
                'fields' => $fieldManager->getApiFormFields(),
            ]);
        } catch (\Exception $e) {
            return $this->error('Failed to fetch list fields: ' . $e->getMessage());
        }
    }

    public function index(Request $request, string $module)
{
    try {
        $user = auth()->user();

        $filterId = $request->query('filter_id');

        /**
         * --------------------------------------------------
         * 1️⃣ Try requested filter
         * --------------------------------------------------
         */
        $filter = null;

        if ($filterId) {
            $filter = Filter::query()
                ->where('id', $filterId)
                ->where('deleted', 0)
                ->where('organization_id', $user->organization_id)
                ->first();
        }

        /**
         * --------------------------------------------------
         * 2️⃣ Fallback to default filter if invalid / missing
         * --------------------------------------------------
         */
        if (!$filter) {
            $filter = Filter::query()
                ->where('module_name', $module)
                ->where('organization_id', $user->organization_id)
                ->where('is_default', 1)
                ->where('deleted', 0)
                ->first();
        }

        /**
         * --------------------------------------------------
         * 3️⃣ Final safety check
         * --------------------------------------------------
         */
        if (!$filter) {
            $perPage = (int) $request->query('per_page', 20);
            $page    = (int) $request->query('page', 1);

            $result = FilterService::getFilteredList(
                $module,
                null,
                $perPage,
                $page
            );

            $fieldManager = \App\Models\FieldModelManager::make($module, 'ListView', true);

            return $this->success([
                'is_default' => false,
                'filter_id'  => null,
                'fields'     => $fieldManager->getApiFormFields(),
                'list'       => $result['details'],
                'meta'       => $result['meta'],
                'links'      => $result['links'],
            ]);
        }

        /**
         * --------------------------------------------------
         * 4️⃣ Fetch filtered list
         * --------------------------------------------------
         */
        $perPage = (int) $request->query('per_page', 20);
        $page    = (int) $request->query('page', 1);

        $result = FilterService::getFilteredList(
            $module,
            $filter->id,
            $perPage,
            $page
        );
        $fieldManager = \App\Models\FieldModelManager::make($module, 'ListView', true);
        return $this->success([
            'is_default' => (bool) $filter->is_default,
            'filter_id'  => $filter->id,
            'fields' => $fieldManager->getApiFormFields(),
            'list'       => $result['details'],
            'meta'       => $result['meta'],
            'links'      => $result['links'],
        ]);

    } catch (\Exception $e) {
        return $this->error(
            'Failed to fetch list: ' . $e->getMessage()
        );
    }
}

   public function inlineEdit(Request $request, string $module, string $id)
{
    try {
        $field = $request->input('field');
        $value = $request->input('value');

        if (!$field) {
            return $this->error('Field name is required');
        }

        $record = \App\Services\CRM\RecordObject::make($module, $id, [], 'EditView');
        $fieldManager = \App\Models\FieldModelManager::make($module, 'EditView', true);
        $fieldModel = $fieldManager->getFieldModel($field);

        if (!$record || !$fieldModel) {
            return $this->error('Record or field not found');
        }

        // Permission check after fieldModel is defined
        $permissionService = new PermissionService(auth()->user());
        if (!auth()->user()->is_admin &&
            !$permissionService->canWriteField($module, $fieldModel->getId())
        ) {
            return $this->error('No permission to edit this field');
        }

        // Ensure record is loaded and original values are tracked
        // This is important for audit logging to capture old values
        if (!$record->exists) {
            return $this->error('Record not found');
        }

        // Sync original attributes to ensure audit log captures old values correctly
        // This is critical for inline edits to work with audit logging
        $record->syncOriginal();

        // Validate value based on field definition
        $fieldModel->validate($value);

        // DIFFERENTIATE STANDARD vs CUSTOM FIELD
        if ($fieldModel->isCustomField()) {
            // Custom field → update customAttributes and save
            $record->__set($fieldModel->getFieldName(), $value);
            $record->save();
        } else {
            // Standard field → normal column update
            $column = $fieldModel->getFieldName();
            $record->$column = $value;
            $record->save();
        }

        return $this->success();
    } catch (ValidationException $e) {
        return $this->error($e->getMessage());
    } catch (\Exception $e) {
        return $this->error($e->getMessage());
    }
}


    public function update(Request $request, string $module, string $id)
    {
        try {
            $data = $request->input('data.values', []);

            if (empty($data)) {
                return $this->error('No data received for update');
            }

            $model = RecordObject::make($module, $id, $data, 'EditView');
            $model->save();

            return $this->success(['message' => 'Record updated successfully']);
        } catch (ValidationException $e) {
            return $this->error($e->getMessage());
        } catch (\Exception $e) {
            return $this->error('Error updating record: ' . $e->getMessage());
        }
    }

    public function destroy(string $module, string $id)
    {
        try {
            $record = RecordObject::make($module, $id);
            $record->deleteRecord();

            return $this->success([]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function getAuditLogs(string $module, string $id, int $offset = 0, int $limit = 10)
    {
        return $this->success(
            (new AuditLogService())->fetchAuditLogEntries($id, $module, $offset, $limit)
        );
    }
}