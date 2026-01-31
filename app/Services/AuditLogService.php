<?php

namespace App\Services;

use App\Modules\Api\V1\ModuleRelationFields\Models\ModuleRelationFields;
use Illuminate\Support\Facades\{Auth, Log, DB};
use App\Services\CRM\RecordObject;
use App\Models\FieldModelManager;
use App\Models\CrmField;
use App\Modules\Api\V1\AuditLog\Models\AuditLog;
use App\Modules\Api\V1\GlobalSearchIndex\Models\GlobalSearchIndex;
use App\Constants\AuditLogEventType;

class AuditLogService
{
   public function fetchAuditLogEntries(string $entityId, string $moduleName, int $offset = 0, int $limit = 10)
{
    try {
        $orgId = auth()->user()->organization_id;

        // Base Audit Logs
        $logs = DB::table('audit_log')
            ->where(function ($query) use ($entityId) {
                $query->where('entity_id', $entityId)
                      ->orWhere('related_entity_id', $entityId);
            })
            ->where(function ($query) use ($moduleName) {
                $query->where('entity_name', $moduleName)
                      ->orWhere('related_entity_name', $moduleName);
            })
            ->where('organization_id', $orgId)
            ->orderByDesc('action_timestamp')
            ->get();

        /** --------------------------
         * Related Activity Logs
         * -------------------------- */
        $relatedActivityIds = DB::table('activity_relations')
            ->where([
                'entity_type' => $moduleName,
                'entity_id' => $entityId,
                'organization_id' => $orgId,
                'deleted' => 0,
            ])
            ->pluck('activity_id')
            ->unique()
            ->toArray();

        if (!empty($relatedActivityIds)) {
            $activityLogs = AuditLog::where('entity_name', 'Activity')
                ->whereIn('entity_id', $relatedActivityIds)
                ->where('organization_id', $orgId)
                ->orderByDesc('action_timestamp')
                ->get();

            $logs = collect($logs)->merge($activityLogs);
        } else {
            $logs = collect($logs); // ensure it’s a collection
        }

        /** --------------------------
         * Related Comment Logs
         * -------------------------- */
        // Security: Filter comments by organization_id
        $relatedCommentIds = DB::table('comment_rel')
            ->join('comments', 'comments.id', '=', 'comment_rel.comment_id')
            ->where('comment_rel.parent_module', $moduleName)
            ->where('comment_rel.parent_id', $entityId)
            ->where('comments.organization_id', $orgId)
            ->where('comments.deleted', 0)
            ->pluck('comment_rel.comment_id')
            ->unique()
            ->toArray();

        if (!empty($relatedCommentIds)) {
            $commentLogs = AuditLog::where('entity_name', 'Comment')
                ->whereIn('entity_id', $relatedCommentIds)
                ->where('organization_id', $orgId)
                ->orderByDesc('action_timestamp')
                ->get();

            $logs = $logs->merge($commentLogs);
        }

        /** --------------------------
         * Related Asset Logs
         * -------------------------- */
        // Build assets query safely — only filter when we know the related column
$assetColumnMap = [
    'Contact'  => 'contact_id',
    'Invoice'  => 'invoice_id',
    'Product'  => 'product_id',
    'Lead'     => 'lead_id',
    'Folder'   => 'folder_id',
    'Activity' => 'activity_id', // <-- add this line
];

$relatedAssetIds = [];

if (isset($assetColumnMap[$moduleName])) {
    $column = $assetColumnMap[$moduleName];

    $relatedAssetIds = DB::table('assets')
        ->where('organization_id', $orgId)
        ->where('deleted', 0)
        ->where($column, $entityId)
        ->pluck('id')
        ->unique()
        ->toArray();
}

// If we have related assets, fetch only their audit logs
if (!empty($relatedAssetIds)) {
    $assetLogs = AuditLog::where('entity_name', 'Asset')
        ->where('event_type', 'update')
        ->whereIn('entity_id', $relatedAssetIds)
        ->where('organization_id', $orgId)
        ->orderByDesc('action_timestamp')
        ->get();

    // ensure $logs is a collection before merging (if not already)
    $logs = collect($logs)->merge($assetLogs);
}


        /** --------------------------
         * Sort + Paginate
         * -------------------------- */
        $logs = $logs->sortByDesc('action_timestamp')->slice($offset, $limit)->values();

        /** --------------------------
         * Field Mapping
         * -------------------------- */
        $fields = FieldModelManager::make($moduleName)->getFields();
        $fieldMap = collect($fields)->mapWithKeys(fn($f) => [$f->getFieldName() => $f])->toArray();

        $result = [];

        foreach ($logs as $log) {
            if ($log->organization_id != $orgId) {
                continue;
            }

            $old = json_decode($log->old_value ?: '{}', true) ?: [];
$new = json_decode($log->new_value ?: '{}', true) ?: [];

            unset($old['identifier'], $new['identifier']);

            $changes = [];
            foreach ($new as $field => $newValue) {
                $oldValue = $old[$field] ?? null;
                if ($oldValue !== $newValue) {
                    $fm = $fieldMap[$field] ?? null;
                    $changes[] = [
                        'field_name'  => $fm?->getAPIName() ?? $field,
                        'field_label' => $fm?->getLabel() ?? $field,
                        'old_value'   => $oldValue ?? "-",
                        'new_value'   => $newValue ?? "-",
                        'field_type'  => $fm?->getFieldType() ?? 'string',
                    ];
                }
            }

            /** Actor (User) */
            $actor = RecordObject::make('User', $log->action_by);
            $actorName = $actor ? trim(($actor->first_name ?? '') . ' ' . ($actor->last_name ?? '')) : null;

            /** Handle Special Event Types */
            if ($log->entity_name === 'Activity' && ($log->event_type === 'insert' || $log->event_type === AuditLogEventType::CREATE)) {
                $act = RecordObject::make('Activity', $log->entity_id)->transformToApiFormat();
                $result[] = [
                    'event_type' => AuditLogEventType::RELATE,
                    'action_by' => ['id' => $log->action_by, 'label' => $actorName, 'name' => 'User'],
                    'timestamp' => $log->action_timestamp,
                    'related_entity' => [
                        'name' => 'Activity',
                        'id' => $log->entity_id,
                        'label' => ($act['title'] ?? '') . ' - ' . ($act['activityType'] ?? ''),
                    ],
                ];
                continue;
            }

            if ($log->entity_name === 'Comment' && ($log->event_type === 'insert' || $log->event_type === AuditLogEventType::CREATE)) {
                $comment = RecordObject::make('Comment', $log->entity_id)->transformToApiFormat();
                $creator = RecordObject::make('User', $comment['createdBy'] ?? null);
                $creatorName = $creator ? trim(($creator->first_name ?? '') . ' ' . ($creator->last_name ?? '')) : null;

                $result[] = [
                    'event_type' => AuditLogEventType::RELATE,
                    'action_by' => ['id' => $log->action_by, 'label' => $creatorName, 'name' => 'User'],
                    'timestamp' => $log->action_timestamp,
                    'related_entity' => [
                        'name' => 'Comment',
                        'id' => $log->entity_id,
                        'label' => substr($comment['content'] ?? '', 0, 30) . ((strlen($comment['content'] ?? '') > 30) ? '...' : ''),
                    ],
                ];
                continue;
            }

            /** Normal Entry */
            $entry = [
                'event_type' => $log->event_type,
                'action_by' => ['id' => $log->action_by, 'label' => $actorName, 'name' => 'User'],
                'timestamp' => $log->action_timestamp,
            ];

            if (!empty($changes)) {
                $entry['changes'] = $changes;
            }

            /** Entity Label Resolution */
            $entityLabel = GlobalSearchIndex::where([
                'record_id' => $log->entity_id,
                'organization_id' => $orgId
            ])->value('label');

            if (empty($entityLabel)) {
                try {
                    $recordObj = RecordObject::make($log->entity_name, $log->entity_id);
                    if ($recordObj) {
                        $apiData = $recordObj->transformToApiFormat();
                        $entityLabel = $apiData['firstName'] ?? $apiData['title'] ?? $apiData['subject'] ?? $apiData['content'] ?? 'Unknown';
                    }
                } catch (\Throwable $e) {
                    $entityLabel = 'Unknown';
                }
            }

            /** Handle Relationship Events */
            if (in_array($log->event_type, [AuditLogEventType::RELATE, 'unrelate'])) {
                $isPrimary = ($log->entity_name === $moduleName && $log->entity_id === $entityId);
                $relatedName = $isPrimary ? $log->related_entity_name : $log->entity_name;
                $relatedId = $isPrimary ? $log->related_entity_id : $log->entity_id;

                if ($relatedName && $relatedId) {
                    $relatedLabel = GlobalSearchIndex::where([
                        'record_id' => $relatedId,
                        'organization_id' => $orgId
                    ])->value('label') ?? $entityLabel;

                    $entry['related_entity'] = [
                        'name' => $relatedName,
                        'id' => $relatedId,
                        'label' => $relatedLabel
                    ];
                }
            }

            /** Email / Call / Meeting Metadata */
            if ($log->event_type === AuditLogEventType::EMAIL) {
                $moreInfo = json_decode($log->more_info ?? '{}', true);
                $entry['meta'] = [
                    'subject' => $moreInfo['subject'] ?? $log->email_subject ?? null,
                    'status' => $moreInfo['status'] ?? $log->email_status ?? null,
                    'to' => $moreInfo['to'] ?? json_decode($log->email_to ?? '[]', true),
                    'cc' => $moreInfo['cc'] ?? json_decode($log->email_cc ?? '[]', true),
                ];
            }

            if ($log->event_type === AuditLogEventType::CALL) {
                $moreInfo = json_decode($log->more_info ?? '{}', true);
                $entry['meta'] = [
                    'call_type' => $moreInfo['call_type'] ?? $log->call_type ?? null,
                    'duration_seconds' => $moreInfo['duration_seconds'] ?? $log->call_duration ?? null,
                    'status' => $moreInfo['status'] ?? $log->call_status ?? null,
                ];
            }

            if ($log->event_type === AuditLogEventType::MEETING) {
                $moreInfo = json_decode($log->more_info ?? '{}', true);
                $entry['meta'] = [
                    'meeting_type' => $moreInfo['meeting_type'] ?? null,
                    'duration_minutes' => $moreInfo['duration_minutes'] ?? null,
                    'status' => $moreInfo['status'] ?? null,
                    'attendees' => $moreInfo['attendees'] ?? [],
                ];
            }

            /** Transfer Event Metadata */
            if ($log->event_type === AuditLogEventType::TRANSFER) {
                $moreInfo = json_decode($log->more_info ?? '{}', true);
                $entry['meta'] = [
                    'source_module' => $moreInfo['source_module'] ?? $log->entity_name ?? null,
                    'target_module' => $moreInfo['target_module'] ?? $log->related_entity_name ?? null,
                    'source_id' => $moreInfo['source_id'] ?? $log->entity_id ?? null,
                    'target_id' => $moreInfo['target_id'] ?? $log->related_entity_id ?? null,
                ];
            }

            $result[] = $entry;
        }

        return [
            'logs' => array_values($result),
            'limit' => $limit,
            'offset' => $offset,
            'total' => count($result),
        ];

    } catch (\Throwable $e) {
        \Log::error("Error fetching audit logs: " . $e->getMessage());
        return response()->json(['error' => 'Failed to fetch audit logs'], 500);
    }
}


   public function create(array $data, string $actionDetails, ?string $eventType = null): void
{
// ✅ Skip logging for internal/system entities
    $skipEntities = ['Organization', 'AuditLog', 'User'];
    if (in_array($data['related_entity_name'] ?? null, $skipEntities, true) ||
        in_array($data['entity_name'] ?? null, $skipEntities, true)) {
        return;
    }   
    $old = $data['old_values'] ?? [];
    $new = $data['new_values'] ?? [];
    if (($data['entity_name'] ?? null) === 'Invoice') unset($old['identifier'], $new['identifier']);

    $changesOld = [];
    $changesNew = [];

    foreach ($new as $field => $value) {
        $oldValue = $old[$field] ?? null;
        if ($oldValue !== $value) {
            $changesOld[$field] = $oldValue;
            $changesNew[$field] = $value;
        }
    }

    if (empty($changesNew) && ($data['is_update'] ?? false)) return;

    // Use 'create' instead of 'insert' for consistency with requirements
    $eventType = $eventType ?? (($data['is_update'] ?? false) ? AuditLogEventType::UPDATE : AuditLogEventType::CREATE);
    $ip = $data['more_info']['ip'] ?? request()->ip() ?? '127.0.0.1';
    $agent = $data['more_info']['user_agent'] ?? request()->userAgent() ?? 'cli';

    // ✅ NOT EXIST VALIDATION BEFORE INSERT
    $exists = DB::table('audit_log')
        ->where('entity_name', $data['entity_name'])
        ->where('entity_id', $data['entity_id'])
        ->where('event_type', $eventType)
        ->where('action_details', $actionDetails)
        ->where('action_by', Auth::id())
        ->whereDate('created_at', now()->toDateString()) // optional: prevent same-day duplicates
        ->exists();

    if ($exists) {
        // Prevent duplicate insert
        Log::info('Duplicate audit log prevented', [
            'entity' => $data['entity_name'],
            'id' => $data['entity_id'],
            'event_type' => $eventType,
        ]);
        return;
    }

    // ✅ Proceed to insert only if not exists
    DB::table('audit_log')->insert([
        'event_type' => $eventType,
        'entity_name' => $data['entity_name'],
        'entity_id' => $data['entity_id'],
        'related_entity_name' => $data['related_entity_name'] ?? null,
        'related_entity_id' => $data['related_entity_id'] ?? null,
        'action_by' => Auth::id(),
        'action_details' => $actionDetails,
        'old_value' => json_encode($changesOld, JSON_UNESCAPED_SLASHES) ?? "-",
        'new_value' => json_encode($changesNew, JSON_UNESCAPED_SLASHES) ?? "-",
        'organization_id' => $data['organization_id'] ?? null,
        'more_info' => json_encode($data['more_info'] ?? []),
        'ip_address' => $ip,
        'user_agent' => $agent,
        'created_at' => now(),
        'updated_at' => now(),
        'action_timestamp' => now()->setTimezone('Asia/Kolkata')->format('Y-m-d H:i:s'),
    ]);

    $this->logRelations($data['entity_name'], $data['entity_id'], $new, $old, $data['organization_id'] ?? null);
}

    protected function logRelations(string $entity, string $entityId, array $new, array $old, ?string $orgId): void
    {
        $currentOrg = auth()->user()->organization_id ?? null;
        if ($orgId && $currentOrg && $orgId !== $currentOrg) return;

        $relations = ModuleRelationFields::where(['modulename' => $entity, 'deleted' => 0])->get();

        foreach ($relations as $relation) {
            // Get the database field name from the field_id UUID
            $crmField = CrmField::where('id', $relation->field_id)->where('deleted', 0)->first();
            if (!$crmField) {
                Log::warning('AuditLog: CrmField not found for field_id', [
                    'field_id' => $relation->field_id,
                    'entity' => $entity
                ]);
                continue;
            }
            
            $fieldName = $crmField->fieldname; // This is the database field name (e.g., 'invoice_id', 'contact_id')
            $relatedModule = $relation->related_module;
            
            // Handle both single values and arrays for relationship fields
            $newValue = $new[$fieldName] ?? null;
            $oldValue = $old[$fieldName] ?? null;
            
            // Convert single values to arrays for comparison
            $newIds = is_array($newValue) ? $newValue : ($newValue ? [$newValue] : []);
            $oldIds = is_array($oldValue) ? $oldValue : ($oldValue ? [$oldValue] : []);
            
            $added = array_diff($newIds, $oldIds);
            $removed = array_diff($oldIds, $newIds);
            
            foreach ($removed as $id) {
                if (!empty($id)) {
                    $this->unrelate($entity, $entityId, $relatedModule, $id, $orgId);
                }
            }
            foreach ($added as $id) {
                if (!empty($id)) {
                    $this->relate($entity, $entityId, $relatedModule, $id, $orgId);
                }
            }
        }
    }

    public function relate(string $entity, string $entityId, string $relatedEntity, string $relatedId, ?string $orgId, ?string $actionBy = null): void
    {
        if ($relatedEntity === 'Organization' || $relatedEntity === 'User') return;

        DB::table('audit_log')->insert([
            'event_type' => AuditLogEventType::RELATE,
            'entity_name' => $entity,
            'entity_id' => $entityId,
            'related_entity_name' => $relatedEntity,
            'related_entity_id' => $relatedId,
            'action_by' => $actionBy ?: Auth::id(),
            'action_details' => "$entity related to $relatedEntity",
            'organization_id' => $orgId,
            'ip_address' => request()->ip() ?? '127.0.0.1',
            'user_agent' => request()->userAgent() ?? 'cli',
            'created_at' => now(),
            'updated_at' => now(),
            'action_timestamp' => now()->setTimezone('Asia/Kolkata')->format('Y-m-d H:i:s'),
        ]);
    }

    public function unrelate(string $entity, string $entityId, string $relatedEntity, string $relatedId, ?string $orgId, ?string $actionBy = null): void
    {
        DB::table('audit_log')->insert([
            'event_type' => 'unrelate',
            'entity_name' => $entity,
            'entity_id' => $entityId,
            'related_entity_name' => $relatedEntity,
            'related_entity_id' => $relatedId,
            'action_by' => $actionBy ?: Auth::id(),
            'action_details' => "$entity unrelated from $relatedEntity",
            'organization_id' => $orgId,
            'ip_address' => request()->ip() ?? '127.0.0.1',
            'user_agent' => request()->userAgent() ?? 'cli',
            'created_at' => now(),
            'updated_at' => now(),
            'action_timestamp' => now()->setTimezone('Asia/Kolkata')->format('Y-m-d H:i:s'),
        ]);
    }

    public function delete(string $entity, string $entityId, ?string $orgId = null, ?string $actionBy = null, ?array $oldValues = null): void
    {
        $relations = ModuleRelationFields::where(['modulename' => $entity, 'deleted' => 0])->get();
        foreach ($relations as $relation) {
            $relatedIds = $this->getRelatedIds($entity, $entityId, $relation->field_id);
            foreach ($relatedIds as $id) $this->unrelate($entity, $entityId, $relation->related_module, $id, $orgId, $actionBy);
        }

        DB::table('audit_log')->insert([
            'event_type' => AuditLogEventType::DELETE,
            'entity_name' => $entity,
            'entity_id' => $entityId,
            'action_by' => $actionBy ?: Auth::id(),
            'action_details' => "$entity deleted",
            'old_value' => $oldValues ? json_encode($oldValues, JSON_UNESCAPED_SLASHES) : "-",
            'new_value' => "-",
            'organization_id' => $orgId,
            'ip_address' => request()->ip() ?? '127.0.0.1',
            'user_agent' => request()->userAgent() ?? 'cli',
            'created_at' => now(),
            'updated_at' => now(),
            'action_timestamp' => now()->setTimezone('Asia/Kolkata')->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Create an email event audit log
     * 
     * @param string $entity Entity name (e.g., 'Contact', 'Lead')
     * @param string $entityId Entity ID
     * @param array $metadata Email metadata (subject, to, cc, status, etc.)
     * @param string|null $orgId Organization ID
     * @param string|null $actionBy User ID who performed action
     */
    public function logEmail(string $entity, string $entityId, array $metadata = [], ?string $orgId = null, ?string $actionBy = null): void
    {
        $orgId = $orgId ?? auth()->user()->organization_id ?? null;
        
        DB::table('audit_log')->insert([
            'event_type' => AuditLogEventType::EMAIL,
            'entity_name' => $entity,
            'entity_id' => $entityId,
            'action_by' => $actionBy ?: Auth::id(),
            'action_details' => "Email sent to {$entity}",
            'organization_id' => $orgId,
            'ip_address' => request()->ip() ?? '127.0.0.1',
            'user_agent' => request()->userAgent() ?? 'cli',
            'more_info' => json_encode($metadata),
            'created_at' => now(),
            'updated_at' => now(),
            'action_timestamp' => now()->setTimezone('Asia/Kolkata')->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Create a call event audit log
     * 
     * @param string $entity Entity name (e.g., 'Contact', 'Lead')
     * @param string $entityId Entity ID
     * @param array $metadata Call metadata (call_type, duration_seconds, status, etc.)
     * @param string|null $orgId Organization ID
     * @param string|null $actionBy User ID who performed action
     */
    public function logCall(string $entity, string $entityId, array $metadata = [], ?string $orgId = null, ?string $actionBy = null): void
    {
        $orgId = $orgId ?? auth()->user()->organization_id ?? null;
        
        DB::table('audit_log')->insert([
            'event_type' => AuditLogEventType::CALL,
            'entity_name' => $entity,
            'entity_id' => $entityId,
            'action_by' => $actionBy ?: Auth::id(),
            'action_details' => "Call made to {$entity}",
            'organization_id' => $orgId,
            'ip_address' => request()->ip() ?? '127.0.0.1',
            'user_agent' => request()->userAgent() ?? 'cli',
            'more_info' => json_encode($metadata),
            'created_at' => now(),
            'updated_at' => now(),
            'action_timestamp' => now()->setTimezone('Asia/Kolkata')->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Create a meeting event audit log
     * 
     * @param string $entity Entity name (e.g., 'Contact', 'Lead', 'Activity')
     * @param string $entityId Entity ID
     * @param array $metadata Meeting metadata (meeting_type, duration_minutes, status, attendees, etc.)
     * @param string|null $orgId Organization ID
     * @param string|null $actionBy User ID who performed action
     */
    public function logMeeting(string $entity, string $entityId, array $metadata = [], ?string $orgId = null, ?string $actionBy = null): void
    {
        $orgId = $orgId ?? auth()->user()->organization_id ?? null;
        
        DB::table('audit_log')->insert([
            'event_type' => AuditLogEventType::MEETING,
            'entity_name' => $entity,
            'entity_id' => $entityId,
            'action_by' => $actionBy ?: Auth::id(),
            'action_details' => "Meeting scheduled for {$entity}",
            'organization_id' => $orgId,
            'ip_address' => request()->ip() ?? '127.0.0.1',
            'user_agent' => request()->userAgent() ?? 'cli',
            'more_info' => json_encode($metadata),
            'created_at' => now(),
            'updated_at' => now(),
            'action_timestamp' => now()->setTimezone('Asia/Kolkata')->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Create a transfer event audit log for record conversions
     * 
     * @param string $sourceEntity Source entity name (e.g., 'Quotation', 'Lead')
     * @param string $sourceId Source entity ID
     * @param string $targetEntity Target entity name (e.g., 'Invoice', 'Contact')
     * @param string $targetId Target entity ID
     * @param array $metadata Additional transfer metadata
     * @param string|null $orgId Organization ID
     * @param string|null $actionBy User ID who performed action
     */
    public function logTransfer(string $sourceEntity, string $sourceId, string $targetEntity, string $targetId, array $metadata = [], ?string $orgId = null, ?string $actionBy = null): void
    {
        $orgId = $orgId ?? auth()->user()->organization_id ?? null;
        
        $metadata = array_merge([
            'source_module' => $sourceEntity,
            'target_module' => $targetEntity,
            'source_id' => $sourceId,
            'target_id' => $targetId,
        ], $metadata);
        
        DB::table('audit_log')->insert([
            'event_type' => AuditLogEventType::TRANSFER,
            'entity_name' => $sourceEntity,
            'entity_id' => $sourceId,
            'related_entity_name' => $targetEntity,
            'related_entity_id' => $targetId,
            'action_by' => $actionBy ?: Auth::id(),
            'action_details' => "{$sourceEntity} transferred to {$targetEntity}",
            'organization_id' => $orgId,
            'ip_address' => request()->ip() ?? '127.0.0.1',
            'user_agent' => request()->userAgent() ?? 'cli',
            'more_info' => json_encode($metadata),
            'created_at' => now(),
            'updated_at' => now(),
            'action_timestamp' => now()->setTimezone('Asia/Kolkata')->format('Y-m-d H:i:s'),
        ]);
    }

    protected function getRelatedIds(string $entity, string $entityId, string $field): array
    {
        $relation = ModuleRelationFields::where(['modulename' => $entity, 'field_id' => $field, 'deleted' => 0])->first();
        if (!$relation) return [];
        $relatedModule = $relation->related_module;
        $model = "\\App\\Modules\\{$relatedModule}\\Models\\{$relatedModule}";
        return class_exists($model) ? $model::where($field, $entityId)->pluck('id')->toArray() : [];
    }
}