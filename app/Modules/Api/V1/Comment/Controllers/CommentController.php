<?php

namespace App\Modules\Api\V1\Comment\Controllers;

use App\Http\Controllers\ApiController;
use App\Modules\Api\V1\Comment\Models\Comment;
use App\Services\CRM\RecordObject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class CommentController extends ApiController
{
	public function save(Request $request, $id = 'new')
{
    $module = 'Comment';
    Log::info("SAVE - Comment save started. ID: {$id}");

    try {
        $isNew = ($id === 'new');
        $id = $isNew ? null : $id;

        // Read input values
        $data = $request->input('data.values', []);
        if (empty($data)) {
            $data = $request->all(); // fallback for form-data
        }

        $relatedRecords = $request->input('data.relatedRecord', []);

        if (empty($data)) {
            return $this->error('No data received');
        }
        if (empty($relatedRecords)) {
            return $this->error('No related records received');
        }

        // 1️⃣ Create or update the comment record
        $model = RecordObject::make($module, $id, $data);
        Log::info("SAVE - Comment model created. Comment ID: {$model->id}");

        // 2️⃣ Save relations BEFORE auto follow-up logic
        if (!empty($relatedRecords)) {
            $model = RecordObject::saveWithRelations($model, $relatedRecords);
            Log::info("SAVE - Comment relations saved (comment_rel).");
        }
        // 3️⃣ AUTO FOLLOW-UP CREATION (must run AFTER relations are saved)
        try {
            $commentText = $data['content'] ?? '';

            // Detect parent Activity
            $parent = DB::table('comment_rel')
                ->where('comment_id', $model->id)
                ->where('parent_module', 'Activity')
                ->first();

            if ($parent) {
                $activityId = $parent->parent_id;

                // Detect follow-up date from comment text
                $followDate = $this->detectFollowUp($commentText);

                if ($followDate) {
                    Log::info("FOLLOW-UP - Follow-up intent detected for {$followDate}");

                    $this->createFollowUpActivity($activityId, $followDate);

                } else {
                    Log::info("FOLLOW-UP - No follow-up keywords found.");
                }
            } else {
                Log::info("FOLLOW-UP - Comment not linked to an Activity. Skipping.");
            }

        } catch (\Exception $e) {
            Log::warning("FOLLOW-UP - Failed: " . $e->getMessage());
        }

        // 4️⃣ Final response
        return $this->success(['id' => $model->id]);

    } catch (ValidationException $e) {
        Log::error("SAVE - Validation error: " . $e->getMessage());
        return $this->error($e->getMessage());
    } catch (\Exception $e) {
        Log::error("SAVE - Exception: " . $e->getMessage());
        return $this->error($e->getMessage());
    }
}
private function createFollowUpActivity($activityId, $followDate)
{
    $org = auth()->user()->organization_id;

    // Fetch parent activity
    $act = DB::table('activities')->where('id', $activityId)->first();
    if (!$act) {
        Log::warning("FOLLOW-UP - Parent activity not found.");
        return;
    }

    // Fetch Contact or Lead
    $customer = DB::table('activity_relations')
        ->where('activity_id', $activityId)
        ->whereIn('entity_type', ['Contact', 'Lead'])
        ->whereIn('relation_type', ['participant', 'contact', 'lead'])
        ->where('deleted', 0)
        ->first();

    if (!$customer) {
        Log::warning("FOLLOW-UP - No Contact/Lead found for activity.");
        return;
    }

    // Avoid duplicate follow-ups
    $title = "Follow-up: " . ($act->title ?? '');

    $exists = DB::table('activities')
        ->where('title', $title)
        ->where('start_date', $followDate->toDateString())
        ->where('organization_id', $org)
        ->where('deleted', 0)
        ->exists();

    if ($exists) {
        Log::info("FOLLOW-UP - Duplicate follow-up activity exists. Skipping.");
        return;
    }

    // Build follow-up activity
    $values = [
        'title' => $title,
        'activityType' => 'Call',
        'startDate' => $followDate->toDateString(),
        'startTime' => '10:00:00',
        'endDate' => $followDate->toDateString(),
        'endTime' => '10:30:00',
        'status' => 'Scheduled',
    ];

    $rels = [
        [
            'relationType' => 'participant',
            'entityType' => $customer->entity_type,
            'entityId' => $customer->entity_id,
        ],
        [
            'relationType' => 'user',
            'entityType' => 'User',
            'entityId' => auth()->user()->id,
        ]
    ];

    // Create Activity
    $newAct = RecordObject::make('Activity', null, $values);
    RecordObject::saveWithRelations($newAct, $rels);

    Log::info("FOLLOW-UP - Created follow-up activity: {$newAct->id}");
}
private function detectFollowUp(string $text)
{
    $text = strtolower(trim($text));
    if ($text === '') return null;

    // 1️⃣ Check for actual follow-up intent (future indicators)
    $hasFutureIndicator = preg_match('/(tomorrow|next|after|in\s+\d+|on\s+\d{4}-\d{2}-\d{2}|on\s+\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4})/', $text);

    if (!$hasFutureIndicator) {
        // No date/time reference → not a follow-up
        return null;
    }

    // 2️⃣ Must contain a follow-up action word
    if (!preg_match('/(call|talk|follow|remind|contact|discuss)/', $text)) {
        return null;
    }

    // 3️⃣ Parse actual date
    return $this->parseFollowUpDate($text);
}
private function parseFollowUpDate(string $text)
{
    $now = Carbon::now();
    $t = strtolower($text);

    // tomorrow
    if (str_contains($t, 'tomorrow')) {
        return Carbon::tomorrow()->startOfDay();
    }

    // day after tomorrow
    if (str_contains($t, 'day after tomorrow')) {
        return $now->addDays(2)->startOfDay();
    }

    // next week
    if (str_contains($t, 'next week')) {
        return $now->addWeek()->startOfDay();
    }

    // next month
    if (str_contains($t, 'next month')) {
        return $now->addMonth()->startOfDay();
    }

    // after X days
    if (preg_match('/after\s+(\d+)\s+day/', $t, $m)) {
        return $now->addDays((int)$m[1])->startOfDay();
    }

    // in X days
    if (preg_match('/in\s+(\d+)\s+day/', $t, $m)) {
        return $now->addDays((int)$m[1])->startOfDay();
    }

    // next Monday / next Friday / next Wednesday
    if (preg_match('/next\s+(monday|tuesday|wednesday|thursday|friday|saturday|sunday)/', $t, $m)) {
        return Carbon::parse("next " . $m[1])->startOfDay();
    }

    // coming Monday
    if (preg_match('/coming\s+(monday|tuesday|wednesday|thursday|friday|saturday|sunday)/', $t, $m)) {
        return Carbon::parse("next " . $m[1])->startOfDay();
    }

    // yyyy-mm-dd
    if (preg_match('/\b(\d{4}-\d{2}-\d{2})\b/', $t, $m)) {
        return Carbon::parse($m[1])->startOfDay();
    }

    // dd/mm/yyyy or dd-mm-yyyy
    if (preg_match('/\b(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4})\b/', $t, $m)) {
        try {
            return Carbon::parse($m[1])->startOfDay();
        } catch (\Exception $e) {}
    }

    // No valid time indicator
    return null;
}

    public function myComments(Request $request)
	{
		$userId       = $request->user()->id;
		$createdBy    = $request->query('user');

		$query = Comment::with(['relations', 'createdBy:id,first_name,last_name'])
			->when($createdBy, fn($q) => $q->where('created_by', $createdBy), fn($q) => $q->where('created_by', $userId));

		$org_id = auth()->user()->organization_id ?? null;
		$comments = $query->orderBy('created_at', 'desc')->where('deleted', 0)->where('organization_id', $org_id)->get();


		$transformed = $comments->map(function ($comment) {
			return [
				"values" => $comment->transformToApiFormat(),
				"relatedRecords" => $comment->relations
				 ->map(function ($relation) {
					 try {
						 $record = RecordObject::make($relation->parent_module, $relation->parent_id);
						 $entityData = $record->transformToApiFormat();

						 $label = trim(($entityData['firstName'] ?? '') . ' ' . ($entityData['lastName'] ?? '')) ?: null;

						 if (!$label) {
							 $labelConfig = DB::table('module_record_label_fields')
								 ->where('module_name', $relation->parent_module)
								 ->value('field_name');

							 if ($labelConfig) {
								 $fields = explode(',', $labelConfig);
								 $labelParts = array_filter(array_map(
									 fn($field) => $entityData[trim($field)] ?? null,
									 $fields
								 ));
								 $label = trim(implode(' ', $labelParts)) ?: null;
							 }
						 }

						 return [
							 'id'           => $record->id,
							 "label"        => $label,
							 "parentModule" => $relation->parent_module,
							 "parentId"     => $relation->parent_id,
						 ];
					 } catch (\Exception $e) {
						 return null;
					 }
				 })
				 ->filter()
				 ->values()
			];
		});

		return $this->success($transformed);
	}
	public function getEntityComments(Request $request, $module, $parent_id)
{
    try {
        $orgId = auth()->user()->organization_id;
        
        $comments = Comment::withoutGlobalScopes()
            ->where('deleted', 0)
            ->where('organization_id', $orgId)
            ->whereHas('relations', function ($q) use ($module, $parent_id) {
                $q->where('parent_module', $module)
                  ->where('parent_id', $parent_id);
            })
            ->orderBy('created_at', 'desc')
            ->get();

        $transformed = $comments->map(function ($comment) {
            return [
                "values" => $comment->transformToApiFormat(),
                "relatedRecords" => $comment->relations
                    ->map(function ($relation) {
                        try {
                            $record = RecordObject::make($relation->parent_module, $relation->parent_id, [], "DetailView");
                            $entityData = $record->transformToApiFormat();

                            $label = trim(($entityData['firstName'] ?? '') . ' ' . ($entityData['lastName'] ?? '')) ?: null;

                            if (!$label) {
                                $labelConfig = DB::table('module_record_label_fields')
                                    ->where('module_name', $relation->parent_module)
                                    ->value('field_name');

                                if ($labelConfig) {
                                    $fields = explode(',', $labelConfig);
                                    $labelParts = array_filter(array_map(
                                        fn($field) => $entityData[trim($field)] ?? null,
                                        $fields
                                    ));
                                    $label = trim(implode(' ', $labelParts)) ?: null;
                                }
                            }

                            return [
                                'id'           => $record->id,
                                "label"        => $label,
                                "parentModule" => $relation->parent_module,
                                "parentId"     => $relation->parent_id,
                            ];
                        } catch (\Exception $e) {
                            return null;
                        }
                    })
                    ->filter()
                    ->values()
            ];
        });

        return $this->success($transformed);
    } catch (\Exception $e) {
        return $this->error("Failed to fetch comments: " . $e->getMessage());
    }
}


}
