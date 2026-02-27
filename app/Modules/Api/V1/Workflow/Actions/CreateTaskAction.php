<?php

namespace App\Modules\Api\V1\Workflow\Actions;

use App\Models\WorkflowQueue;
use App\Modules\Api\V1\Activity\Models\Activity;
use App\Modules\Api\V1\ActivityRelation\Models\ActivityRelation;
use Illuminate\Support\Facades\Log;

class CreateTaskAction implements WorkflowActionInterface
{
    /**
     * Execute the workflow action.
     *
     * @param WorkflowQueue $job
     * @return void
     */
    public function handle(WorkflowQueue $job): void
    {
        $params = $job->params;
        $context = $params['context'] ?? null;

        if (!$context) {
            Log::error("CreateTaskAction: Missing context for job {$job->id}");
            return;
        }

        try {
            $orgId = $job->organization_id;

            // 1. Prepare Activity Data (Use camelCase fields as per Activity module requirements)
            $activityType = strtolower($params['activityType'] ?? ($params['activity_type'] ?? 'task'));
            $status = strtolower($params['status'] ?? ($params['activity_status'] ?? 'scheduled'));

            // Map common variations to system-accepted values
            if ($status === 'not started') {
                $status = 'scheduled';
            }

            $data = [
                'title' => $params['title'] ?? ($params['subject'] ?? 'Workflow Generated Task'),
                'description' => $params['description'] ?? '',
                'activityType' => $activityType,
                'startDate' => $params['startDate'] ?? ($params['start_date'] ?? now()->format('Y-m-d')),
                'startTime' => $params['startTime'] ?? ($params['start_time'] ?? now()->format('H:i:s')),
                'endDate' => $params['endDate'] ?? ($params['end_date'] ?? now()->addDays(2)->format('Y-m-d')),
                'endTime' => $params['endTime'] ?? ($params['end_time'] ?? now()->format('H:i:s')),
                'status' => $status,
            ];

            // 2. Prepare Relations
            $relatedRecords = [
                'activity_relations' => [
                    [
                        'entityType' => $context['module'],
                        'entityId' => $context['record_id'],
                        'relationType' => 'link',
                    ]
                ]
            ];

            // 3. Create Record using standard utility
            $model = \App\Services\CRM\RecordObject::make('Activity', 'new', $data, 'CreateView');

            // Bypass mass-assignment protection for organization_id and created_by
            $model->organization_id = $orgId;
            $model->created_by = $job->user_id;

            // 4. Save with relations (handles numbering, hooks, activity_relations)
            \App\Services\CRM\RecordObject::saveWithRelations($model, $relatedRecords);

            Log::info("CreateTaskAction: Successfully created task and relations for {$context['module']}:{$context['record_id']}");

        } catch (\Throwable $e) {
            Log::error("CreateTaskAction Failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Validate and prepare Task action parameters.
     */
    public function save(array $params, string $module, string $orgId): array
    {
        if (empty($params['title']) && empty($params['subject'])) {
            throw new \Exception("Task title is required.");
        }

        return $params;
    }

    /**
     * Define the dynamic fields required by this action type.
     *
     * @return array
     */
    public function getParamsFields(): array
    {
        return [
            [
                'name' => 'title',
                'label' => 'Task Title',
                'type' => 'string',
                'required' => true,
            ],
            [
                'name' => 'description',
                'label' => 'Description',
                'type' => 'textarea',
                'required' => false,
            ],
            [
                'name' => 'activity_type',
                'label' => 'Activity Type',
                'type' => 'picklist',
                'options' => ['Task' => 'Task', 'Meeting' => 'Meeting', 'Call' => 'Call'],
                'required' => false,
                'default' => 'Task'
            ],
            [
                'name' => 'status',
                'label' => 'Status',
                'type' => 'picklist',
                'options' => ['Scheduled' => 'Scheduled', 'In Progress' => 'In Progress', 'Completed' => 'Completed'],
                'required' => false,
                'default' => 'Scheduled'
            ]
        ];
    }
}
