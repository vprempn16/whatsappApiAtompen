<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use App\Services\HookManager;
use App\Models\AuditLog;

use App\Modules\Api\V1\Checklist\Models\Checklist;
use App\Modules\Api\V1\ChecklistItem\Models\ChecklistItem;
use App\Modules\Api\V1\ChecklistTemplate\Models\ChecklistTemplate;
use App\Modules\Api\V1\ChecklistTemplateItem\Models\ChecklistTemplateItem;
use App\Modules\Api\V1\Task\Models\Task;
use App\Modules\Api\V1\GlobalSearchIndex\Models\GlobalSearchIndex;
use App\Modules\Api\V1\SearchableModule\Models\SearchableModule;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;


class AppServiceProvider extends ServiceProvider
{
	public static $isProcessing = false;

	public function boot()
	{
		// Force HTTPS for production and local environments when using HTTPS
		if (app()->environment('production') || request()->secure() || (env('APP_URL', '') !== '' && strpos(env('APP_URL', ''), 'https://') === 0)) {
			URL::forceScheme('https');
		}

		HookManager::registerHook('*', 'afterSave', [$this, 'handleAfterSave']);
		HookManager::registerHook('*', 'afterDelete', [$this, 'handleAfterDelete']);

	}

	public function handleAfterSave(array $data)
	{
		if (static::$isProcessing) {
			return;
		}

		$module = $data['module'] ?? $data['entity_name'] ?? null;
		if (in_array($module, ['GlobalSearchIndex', 'AuditLog'])) {
			return;
		}

		static::$isProcessing = true;

		Log::info("Global afterSave hooks registered successfully - ID : " . ($data['entity_id'] ?? 'N/A'));

		try {
			$this->createSearchIndex($data);
			$this->createAuditLog($data, 'afterSave');
			$this->handleActivityTypeLogging($data);
			$this->handleInvoiceChecklist($data);
			$this->handleValidateLeadIsConverted($data);

		} catch (\Exception $e) {
			Log::error("Global afterSave hook failed: " . $e->getMessage(), [
				'error' => $e->getMessage(),
				'data' => $data
			]);
		} finally {
			static::$isProcessing = false;
		}
	}
	protected function handleValidateLeadIsConverted(array $data)
	{
		try {
			if (
				isset($data['module'], $data['new_values']['is_converted']) &&
				$data['module'] === 'Lead' &&
				$data['new_values']['is_converted'] === 1
			) {
				return false;
			}
		} catch (\Exception $e) {
			Log::error("Lead conversion validation failed: " . $e->getMessage(), [
				'error' => $e->getMessage(),
				'data' => $data
			]);
		}

		return true; // or null if you prefer
	}
	protected function createAuditLog(array $data, string $actionDetails)
	{
		try {
			$auditService = new AuditLogService();
			$auditService->create($data, $actionDetails);
		} catch (\Exception $e) {
			Log::error("Audit log creation failed: " . $e->getMessage(), [
				'error' => $e->getMessage(),
				'data' => $data
			]);
		}
	}

	/**
	 * Handle Activity type-specific audit logging (Email, Call, Meeting)
	 */
	protected function handleActivityTypeLogging(array $data)
	{
		try {
			// Only process Activity module
			if (($data['entity_name'] ?? null) !== 'Activity') {
				return;
			}

			$activityType = $data['new_values']['activityType'] ?? $data['new_values']['activity_type'] ?? null;
			$activityId = $data['entity_id'] ?? null;
			$orgId = $data['organization_id'] ?? null;
			$userId = auth()->user()->id ?? null;

			if (!$activityType || !$activityId) {
				return;
			}

			$auditService = new AuditLogService();

			// Normalize activity type to lowercase for comparison
			$activityTypeLower = strtolower($activityType);

			// Create specific event type based on activity type
			if ($activityTypeLower === 'email') {
				$metadata = [
					'subject' => $data['new_values']['title'] ?? $data['new_values']['subject'] ?? null,
					'status' => $data['new_values']['status'] ?? null,
					'to' => $data['new_values']['to'] ?? [],
					'cc' => $data['new_values']['cc'] ?? [],
				];
				$auditService->logEmail('Activity', $activityId, $metadata, $orgId, $userId);
			} elseif ($activityTypeLower === 'call') {
				$metadata = [
					'call_type' => $data['new_values']['callType'] ?? $data['new_values']['call_type'] ?? null,
					'duration_seconds' => $data['new_values']['durationSeconds'] ?? $data['new_values']['duration_seconds'] ?? null,
					'status' => $data['new_values']['status'] ?? null,
				];
				$auditService->logCall('Activity', $activityId, $metadata, $orgId, $userId);
			} elseif ($activityTypeLower === 'meeting') {
				$metadata = [
					'meeting_type' => $data['new_values']['meetingType'] ?? $data['new_values']['meeting_type'] ?? null,
					'duration_minutes' => $this->calculateDurationMinutes($data['new_values']),
					'status' => $data['new_values']['status'] ?? null,
					'attendees' => $data['new_values']['attendees'] ?? [],
				];
				$auditService->logMeeting('Activity', $activityId, $metadata, $orgId, $userId);
			}
		} catch (\Exception $e) {
			Log::error("Activity type logging failed: " . $e->getMessage(), [
				'error' => $e->getMessage(),
				'data' => $data
			]);
		}
	}

	/**
	 * Calculate duration in minutes from start/end date and time
	 */
	protected function calculateDurationMinutes(array $values): ?int
	{
		$startDate = $values['startDate'] ?? $values['start_date'] ?? null;
		$startTime = $values['startTime'] ?? $values['start_time'] ?? null;
		$endDate = $values['endDate'] ?? $values['end_date'] ?? null;
		$endTime = $values['endTime'] ?? $values['end_time'] ?? null;

		if (!$startDate || !$endDate) {
			return null;
		}

		try {
			$start = Carbon::parse($startDate . ' ' . ($startTime ?? '00:00:00'));
			$end = Carbon::parse($endDate . ' ' . ($endTime ?? '00:00:00'));
			return $start->diffInMinutes($end);
		} catch (\Exception $e) {
			return null;
		}
	}


	protected function createSearchIndex(array $data)
	{
		try {
			$module = $data['entity_name'];
			$recordId = $data['entity_id'];
			$orgId = $data['organization_id'];
			/**
			 * ----------------------------------------------------
			 * PRODUCT MODULE (SPECIAL LOGIC)
			 * ----------------------------------------------------
			 * - Label = name only
			 * - Search text = all searchable fields except name
			 */
			if ($module === 'Product') {

				// Label is only the product name
				$labelString = $data['new_values']['name'] ?? 'Unnamed Product';

				// Fetch searchable fields
				$configs = SearchableModule::where('module_name', $module)->get();

				$searchText = [];

				foreach ($configs as $config) {
					$field = $config->searchable_field;

					// Skip name from search text
					if ($field === 'name') {
						continue;
					}

					$value = $data['new_values'][$field] ?? null;

					if (!empty($value)) {
						$searchText[$field] = $value;
					}
				}

				$this->upsertGlobalSearchIndex(
					$orgId,
					$module,
					$recordId,
					$labelString,
					$searchText
				);

				return;
			}

			// ✅ If module is Contact, User, or Lead → label = first_name + last_name
			if (in_array($module, ['Contact', 'User', 'Lead'])) {
				$firstName = $data['new_values']['first_name'] ?? '';
				$lastName = $data['new_values']['last_name'] ?? '';
				$labelString = trim($firstName . ' ' . $lastName);

				if (empty($labelString)) {
					// fallback if names are missing
					$labelString = $data['new_values']['name'] ?? 'Unnamed ' . $module;
				}

				$searchText = [
					'first_name' => $firstName,
					'last_name' => $lastName,
				];

				// ✅ Upsert into global_search_index
				$this->upsertGlobalSearchIndex($orgId, $module, $recordId, $labelString, $searchText);
				return;
			}

			// Normal modules (not Contact/User/Lead)
			$configs = SearchableModule::where('module_name', $module)->get();

			if ($configs->isEmpty()) {
				return; // no mapping, skip
			}

			$labels = [];
			$searchText = [];

			foreach ($configs as $config) {
				$field = $config->searchable_field;
				$value = $data['new_values'][$field] ?? null;

				if (!empty($value)) {
					$labels[] = $value;
					$searchText[$field] = $value;
				}
			}

			if (empty($labels)) {
				return; // nothing to index
			}

			$labelString = implode(' ', $labels);
			$this->upsertGlobalSearchIndex($orgId, $module, $recordId, $labelString, $searchText);

		} catch (\Exception $e) {
			Log::error("Failed to create or update search index", [
				'error' => $e->getMessage(),
				'data' => $data,
			]);
		}
	}
	protected function upsertGlobalSearchIndex($orgId, $module, $recordId, $labelString, $searchText)
	{
		// Security: Always filter by organization_id to prevent cross-organization data access
		$existing = GlobalSearchIndex::where('module_name', $module)
			->where('record_id', $recordId)
			->where('organization_id', $orgId)
			->first();

		if ($existing) {
			$existing->update([
				'organization_id' => $orgId,
				'label' => mb_substr($labelString, 0, 255),
				'search_text' => json_encode($searchText),
			]);

			Log::info("Search index updated for {$module}", [
				'record_id' => $recordId,
				'label' => $labelString,
			]);
		} else {
			$id = (string) \Illuminate\Support\Str::uuid();
			$now = now();
			DB::table('global_search_index')->insert([
				'id' => $id,
				'organization_id' => $orgId,
				'module_name' => $module,
				'record_id' => $recordId,
				'label' => mb_substr($labelString, 0, 255),
				'search_text' => json_encode($searchText),
				'deleted' => 0,
				'created_at' => $now,
				'updated_at' => $now,
				'created_by' => auth()->user()?->id,
			]);

			Log::info("Search index created for {$module}", [
				'record_id' => $recordId,
				'label' => $labelString,
			]);
		}
	}

	public function handleAfterDelete(array $data)
	{
		if (static::$isProcessing) {
			return;
		}
		static::$isProcessing = true;

		try {
			// Use AuditLogService for consistent delete logging
			$auditService = new AuditLogService();
			$auditService->delete(
				$data['entity_name'],
				$data['entity_id'],
				$data['organization_id'] ?? null,
				auth()->user()->id ?? 'system',
				$data['old_values'] ?? null
			);

			Log::info("AuditLog created for afterDelete", [
				'module' => $data['module'],
				'entity' => $data['entity_id'],
				'event' => 'afterDelete',
			]);

			// Intelligent update of global_search_index: mark matching entries as deleted
			try {
				$orgId = $data['organization_id'] ?? null;
				if (!$orgId) {
					return; // Skip if no organization_id
				}
				// Security: Always filter by organization_id to prevent cross-organization updates
				$affected = DB::table('global_search_index')
					->where('organization_id', $orgId)
					->where(function ($q) use ($data) {
						$q->where('module_name', $data['entity_name'])
							->where('record_id', $data['entity_id']);
					})
					->orWhere(function ($q) use ($data, $orgId) {
						$q->where('record_id', $data['entity_id'])
							->where('organization_id', $orgId);
					})
					->update([
						'deleted' => 1,
						'updated_at' => now(),
					]);

				if ($affected) {
					Log::info("Global search index entries marked deleted", [
						'entity_name' => $data['entity_name'],
						'entity_id' => $data['entity_id'],
						'affected_rows' => $affected,
					]);
				} else {
					Log::info("No global search index entries found to mark deleted", [
						'entity_name' => $data['entity_name'],
						'entity_id' => $data['entity_id'],
					]);
				}
			} catch (\Exception $e) {
				Log::error("Failed to update global_search_index on delete", [
					'error' => $e->getMessage(),
					'data' => $data,
				]);
			}

		} catch (\Exception $e) {
			Log::error("Failed to create AuditLog in afterDelete hook", [
				'error' => $e->getMessage(),
				'data' => $data,
			]);
		} finally {
			static::$isProcessing = false;
		}
	}
	public function handleInvoiceChecklist(array $data)
	{
		try {
			if ($data['entity_name'] !== 'Invoice') {
				return;
			}

			if (!isset($data['new_values']['status']) || $data['new_values']['status'] !== 'In Progress') {
				return;
			}

			$invoiceId = $data['entity_id'];
			$userId = auth()->user()->id;
			$orgId = auth()->user()->organization_id;

			$template = ChecklistTemplate::where('module', 'Invoice')
				->where('is_active', 1)
				->first();

			if (empty($template)) {
				Log::warning("No active checklist template found for Invoice.");
				return;
			}

			$checklist = Checklist::create([
				'recordId' => $invoiceId,
				'checklistTemplateId' => $template->id,
				'status' => 'Assigned',
				'createdBy' => $userId,
				'organizationId' => $orgId,
			]);

			$items = ChecklistTemplateItem::where('checklist_template_id', $template->id)->get();

			foreach ($items as $item) {
				$checklistItem = ChecklistItem::create([
					'checklistId' => $checklist->id,
					'templateItemId' => $item->id,
					'itemName' => $item->item_name,
					'itemType' => $item->item_type,
					'status' => $item->status,
					'notes' => $item->notes,
					'photoUrl' => $item->photo_url,
					'orderIndex' => $item->order_index,
					'assignedTo' => $userId,
					'organizationId' => $orgId,
					'createdBy' => $userId,
				]);
			}

			Log::info("Checklist and tasks created for invoice", [
				'invoice_id' => $invoiceId,
				'checklist_id' => $checklist->id,
				'user_id' => $userId,
			]);

		} catch (\Exception $e) {
			Log::error("Failed to create checklist and tasks for invoice", [
				'error' => $e->getMessage(),
				'data' => $data,
			]);
		}
	}

}
