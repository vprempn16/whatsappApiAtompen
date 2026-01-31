<?php

namespace App\Modules\Api\V1\Activity\Controllers;

use App\Http\Controllers\ApiController;
use Illuminate\Http\Request;
use App\Services\CRM\RecordObject;
use Illuminate\Validation\ValidationException;
use App\Modules\Api\V1\Activity\Models\Activity;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use OpenAI\Laravel\Facades\OpenAI;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;


class ActivityController extends ApiController
{
	public function getActivityDetails(Request $request, string $id)
{
    try {
        $orgId = auth()->user()->organization_id;

        $activity = Activity::with('relations')
            ->where('id', $id)
            ->where('organization_id', $orgId)
            ->where('deleted', 0)
            ->first();
       if (!$activity) {
            return $this->error("Activity not found for ID: {$id}");
        }

        // Transform base data
        $activityData = $activity->transformToApiFormat();

        // Related records (batch loaded to avoid N+1)
        [$recordsByType, $labelConfigs] = $this->preloadRelationEntities($activity->relations);
        $relatedRecords = $this->buildRelatedRecords(
            $activity->relations,
            $recordsByType,
            $labelConfigs,
            true
        );

        // Optional comments
        $includeComments = $request->query('include_comments', false);
        $comments = [];

        if ($includeComments) {
            $comments = DB::table('comment_rel')
    ->join('comments', 'comments.id', '=', 'comment_rel.comment_id')
    ->select(
        'comments.id',
        'comments.content',
        'comments.created_at',
        'comments.created_by'
    )
    ->where('comment_rel.parent_id', $id)
    ->where('comment_rel.parent_module', 'Activity')
    ->where('comments.deleted', 0)
    ->where('comments.organization_id', $orgId) // ✅ FIX
    ->orderBy('comments.created_at', 'asc')
    ->get();

        }

        // Final unified structure like myActivities
        return $this->success([
            'values'         => $activityData,
            'relatedRecords' => $relatedRecords,
            'comments'       => $comments,
        ]);
    } catch (\Exception $e) {
        Log::error("Failed to fetch Activity details for ID {$id}: " . $e->getMessage());
        return $this->error("Failed to fetch Activity details: " . $e->getMessage());
    }
}


	/**
     * Generate AI-based summary for an activity by fetching its related comments.
     * Route: POST /api/v1/Activity/{id}/pre-summary
     */
   public function preSummary(Request $request, string $id)
{
    try {
        $orgId = auth()->user()->organization_id;

        Log::info('AI PreSummary started', [
            'activity_id' => $id,
            'org_id'      => $orgId,
        ]);


        $cacheKey = "activity:{$orgId}:{$id}:pre_summary";

$cachedSummary = Cache::get($cacheKey);
if ($cachedSummary) {
    Log::info('AI PreSummary served from cache', [
        'activity_id' => $id,
        'org_id'      => $orgId,
    ]);

    return $this->success([
        'activity_id' => $id,
        'ai_status'   => 'cached',
        'summary'     => $cachedSummary,
    ]);
}
        /* -------------------------------------------------
         * 1️⃣ Load Activity (ORG SAFE)
         * ------------------------------------------------- */
        $activity = DB::table('activities')
            ->where('id', $id)
            ->where('organization_id', $orgId)
            ->where('deleted', 0)
            ->first();

        if (!$activity) {
            return $this->error('Activity not found.');
        }

        $description = trim($activity->description ?? '');

        /* -------------------------------------------------
         * 2️⃣ Load Current Activity Comments (ASC order)
         * ------------------------------------------------- */
        $currentComments = DB::table('comment_rel')
            ->join('comments', 'comments.id', '=', 'comment_rel.comment_id')
            ->where('comment_rel.parent_id', $id)
            ->where('comment_rel.parent_module', 'Activity')
            ->where('comments.deleted', 0)
            ->where('comments.organization_id', $orgId)
            ->orderBy('comments.created_at', 'asc')
            ->select('comments.content', 'comments.created_at')
            ->get();
        
        /* -------------------------------------------------
         * 3️⃣ Build Calls (DETERMINISTIC, NO RANDOM DATA)
         * ------------------------------------------------- */
        $calls = $currentComments->map(function ($comment, $index) {
            return [
                'call_number' => $index + 1,
                'call_date'   => $comment->created_at,
                'comment'     => $comment->content,
            ];
        })->toArray();

        $hasComments = !empty($calls);

        /* -------------------------------------------------
         * 4️⃣ Detect Customer (Contact / Lead)
         * ------------------------------------------------- */
        $customer = DB::table('activity_relations')
            ->where('activity_id', $id)
            ->where('organization_id', $orgId)
            ->where('deleted', 0)
            ->whereIn('entity_type', ['Contact', 'Lead'])
            ->orderByRaw("
                CASE
                    WHEN relation_type = 'contact' THEN 1
                    WHEN relation_type = 'lead' THEN 2
                    WHEN relation_type = 'participant' THEN 3
                    ELSE 4
                END
            ")
            ->first();

        $contactFirstName = '';
        $contactLastName  = '';

        if ($customer) {
            try {
                $customerObj = RecordObject::make(
                    $customer->entity_type,
                    $customer->entity_id
                )->transformToApiFormat();

                $contactFirstName = $customerObj['firstName']
                    ?? $customerObj['first_name']
                    ?? '';

                $contactLastName = $customerObj['lastName']
                    ?? $customerObj['last_name']
                    ?? '';
            } catch (\Exception $e) {
                Log::warning('Customer load failed', [
                    'error' => $e->getMessage()
                ]);
            }
        }

        /* -------------------------------------------------
         * 5️⃣ Load Product / Topic (IF ANY)
         * ------------------------------------------------- */
        $productDetails = null;

        $productRel = DB::table('activity_relations')
            ->where('activity_id', $id)
            ->where('organization_id', $orgId)
            ->where('deleted', 0)
            ->whereIn('relation_type', ['product', 'topic'])
            ->orderByRaw("
                CASE
                    WHEN relation_type = 'product' THEN 1
                    WHEN relation_type = 'topic' THEN 2
                    ELSE 3
                END
            ")
            ->first();

        if ($productRel) {
            try {
                $productObj = RecordObject::make(
                    $productRel->entity_type,
                    $productRel->entity_id
                )->transformToApiFormat();

                $productDetails = json_encode($productObj, JSON_PRETTY_PRINT);
            } catch (\Exception $e) {
                Log::warning('Product load failed', [
                    'error' => $e->getMessage()
                ]);
            }
        }

        /* -------------------------------------------------
         * 6️⃣ Load Previous Activities (LAST 5)
         * ------------------------------------------------- */
        $previousActivitiesJson = '[]';

        if ($customer) {

            $previousActivities = DB::table('activity_relations AS ar')
                ->join('activities AS a', 'a.id', '=', 'ar.activity_id')
                ->where('ar.entity_id', $customer->entity_id)
                ->where('ar.entity_type', $customer->entity_type)
                ->where('ar.organization_id', $orgId)
                ->where('ar.deleted', 0)
                ->where('a.deleted', 0)
                ->where('a.id', '!=', $id)
                ->orderBy('a.created_at', 'desc')
                ->limit(5)
                ->select(
                    'a.id',
                    'a.title',
                    'a.description',
                    'a.activity_type',
                    'a.start_date',
                    'a.status'
                )
                ->get();

            if ($previousActivities->isNotEmpty()) {

                $prevIds = $previousActivities->pluck('id')->toArray();

                $commentsByActivity = DB::table('comment_rel')
                    ->join('comments', 'comments.id', '=', 'comment_rel.comment_id')
                    ->whereIn('comment_rel.parent_id', $prevIds)
                    ->where('comment_rel.parent_module', 'Activity')
                    ->where('comments.deleted', 0)
                    ->where('comments.organization_id', $orgId)
                    ->orderBy('comments.created_at', 'asc')
                    ->select(
                        'comment_rel.parent_id',
                        'comments.content',
                        'comments.created_at'
                    )
                    ->get()
                    ->groupBy('parent_id');

                $previousActivitiesJson = json_encode(
                    $previousActivities->map(function ($act) use ($commentsByActivity) {
                        return [
                            'activity_id'   => $act->id,
                            'title'         => $act->title,
                            'description'   => $act->description,
                            'activity_type' => $act->activity_type,
                            'start_date'    => $act->start_date,
                            'status'        => $act->status,
                            'comments'      => ($commentsByActivity[$act->id] ?? collect())
                                ->map(fn($c) => [
                                    'content'    => $c->content,
                                    'created_at' => $c->created_at,
                                ])
                        ];
                    }),
                    JSON_PRETTY_PRINT
                );
            }
        }

        /* -------------------------------------------------
         * 7️⃣ Build Prompt
         * ------------------------------------------------- */
        $prompt = $this->buildPrompt(
            $id,
            $calls,
            $hasComments,
            $description,
            $previousActivitiesJson,
            $productDetails,
            $contactFirstName,
            $contactLastName,
            auth()->user()->first_name ?? '',
            auth()->user()->last_name ?? ''
        );

        /* -------------------------------------------------
         * 8️⃣ AI CALL (READ ONLY)
         * ------------------------------------------------- */
        $response = OpenAI::chat()->create([
            'model' => 'gpt-4o',
            'messages' => [
                ['role' => 'system', 'content' => 'Return only valid JSON.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.4,
            'max_tokens'  => 900,
        ]);

        $content = trim($response->choices[0]->message->content ?? '');
        $content = preg_replace('/^```(?:json)?|```$/m', '', $content);

        $json = json_decode($content, true);


        if (!is_array($json)) {
            Log::warning('AI PreSummary invalid JSON', ['activity_id' => $id]);

            return $this->success([
                'activity_id' => $id,
                'ai_status'   => 'fallback',
                'summary'     => [],
            ], 'AI response invalid, fallback applied.');
        }
        Cache::put($cacheKey, $json['activity_summary'] ?? [], now()->addMinutes(10));
        return $this->success([
            'activity_id' => $id,
            'ai_status'   => 'success',
            'summary'     => $json['activity_summary'] ?? [],
        ]);

    } catch (\Exception $e) {
        Log::error('AI PreSummary failed', [
            'activity_id' => $id,
            'error'       => $e->getMessage(),
        ]);

        return $this->error('AI summary failed.');
    }
}
private function buildPrompt(
    string $activityId,
    array $calls,
    bool $hasComments,
    string $description,
    ?string $previousActivitiesJson = null,
    ?string $productDetails = null,
    ?string $contactFirstName = '',
    ?string $contactLastName = '',
    ?string $userFirstName = '',
    ?string $userLastName = ''
): string
{
    $callsJson = json_encode($calls, JSON_PRETTY_PRINT);
    $previousJson = $previousActivitiesJson ?: '[]';
    $productJson = $productDetails ?: 'No product details available.';

    $contactName = trim($contactFirstName . ' ' . $contactLastName) ?: 'the customer';
    $userName = trim($userFirstName . ' ' . $userLastName) ?: 'our representative';

    return <<<PROMPT
You are an intelligent **CRM AI Assistant**.  
Your task is to analyze this activity, understand the customer context, and prepare a clear, structured, actionable summary for the salesperson.

---

## PERSONALIZATION CONTEXT
- **Customer Name:** {$contactName}
- **Salesperson Name:** {$userName}

Use these names naturally in your response.  
Never include placeholders like [Customer Name] or [Your Name].

---

## ACTIVITY CONTEXT
**Activity ID:** {$activityId}

**Description:**
{$description}

**Product Details:**
{$productJson}

**Comments (if available):**
{$callsJson}

**Previous Related Activities:**
{$previousJson}

---

## INSTRUCTIONS

1. **If no comments or previous activities exist**, treat this as the **first customer interaction**.  
   - Set `"past_interactions_summary": []`.  
   - Base your insights only on the activity description and product details.

2. **If comments or previous activities exist**, analyze them in order to understand the customer's tone, preferences, and conversation progress.  

3. **Generate a concise, structured summary** that includes:  
   - `"past_interactions_summary"`: summarize each relevant comment or discussion point.  
   - `"key_customer_preferences"`: what the customer is interested in, prefers, or has emphasized.  
   - `"open_questions_for_call"`: what needs follow-up or clarification.  
   - `"recommended_call_strategy"`:  
     - `"opening_line"`: a short, friendly greeting from {$userName} to {$contactName}.  
     - `"focus_points"`: 2–3 important topics to prioritize in the next interaction.  
     - `"tone"`: a one-word or short phrase describing the tone to use (e.g., friendly, confident, empathetic).  
   - `"call_rating"`: integer 1–5 based on positivity and engagement.

4. **Do not suggest or create new activities.**  
   Only focus on summarizing existing context and preparing the next best talking approach.

---
## STRICT JSON OUTPUT FORMAT

Return **only valid JSON** with no extra commentary, explanations, or Markdown formatting.  
IMPORTANT: Do NOT reorder the `past_interactions_summary`. 
Keep the calls in the same order as received. 
The highest call_number must appear first (DESC order).


```json
{
  "activity_summary": {
    "past_interactions_summary": [
       {
                    "call_number": 1,
                    "call_date": "2025-11-12T11:46:34",
                    "call_type": "General Update",
                    "call_mode": "System",
                    "call_duration_minutes": 14,
                    "comment": "Max well expressed interest in the fridge and inquired about price details and potential discounts."
                },
                {
                    "call_number": 2,
                    "call_date": "2025-11-12T11:47:48",
                    "call_type": "General Update",
                    "call_mode": "System",
                    "call_duration_minutes": 8,
                    "comment": "Max well reiterated the need for price and discount details, indicating a plan to purchase within a week."
                }
    ],
    "key_customer_preferences": [
      "string"
    ],
    "open_questions_for_call": [
      "string"
    ],
    "recommended_call_strategy": {
      "opening_line": "Hi {$contactName}, this is {$userName}.",
      "focus_points": [
        "string",
        "string"
      ],
      "tone": "Friendly and professional"
    },
    "call_rating": 1
  },
  "message": "AI summary generated successfully."
}
PROMPT; }


	public function save(Request $request, $id = 'new')
	{
		$module = 'Activity';
		Log::info("SAVE - Entered save method for module: {$module}, id: {$id}");

		try {
			$isNew = ($id === 'new');
			$id = $isNew ? null : $id;

			$data = $request->input('data.values', []);
			if (empty($data)) {
				$data = $request->all(); // fallback for form-data
			}
			$relatedRecords = $request->input('data.relatedRecords', []);

			Log::info("SAVE - Input data:", $data);
			Log::info("SAVE - Related records:", $relatedRecords);

			if (empty($data)) {
				Log::warning("SAVE - No data received for saving");
				return $this->error('No data received for saving');
			}

			if (empty($relatedRecords)) {
				Log::warning("SAVE - No related records received for saving");
				return $this->error('No related records received for saving');
			}
			/** @var \App\Services\CRM\RecordObject|\App\Models\AtomModel $model */
			$model = RecordObject::make($module, $id, $data);
			Log::info("SAVE - RecordObject created with id: " . $model->id);
           // dd($relatedRecords);
			if (!empty($relatedRecords)) {
				$model = RecordObject::saveWithRelations($model, $relatedRecords);
				Log::info("SAVE - Saved with related activity_relations");
			}

			Log::info("SAVE - Successfully saved activity with id: " . $model->id);
			return $this->success(['id' => $model->id]);

		} catch (ValidationException $e) {
			Log::error("SAVE - ValidationException: " . $e->getMessage());
			return $this->error($e->getMessage());
		} catch (\Exception $e) {
			Log::error("SAVE - Exception: " . $e->getMessage());
			return $this->error($e->getMessage());
		}
	}
	public function myActivities(Request $request)
	{
		$userId       = $request->user()->id;
		$startDate    = $request->query('start_date');
		$endDate      = $request->query('end_date');
		$relationType = $request->query('relation_type');
		$createdBy    = $request->query('user');

		$query = Activity::with('relations')
			->when($createdBy, fn($q) => $q->where('created_by', $createdBy), fn($q) => $q->where('created_by', $userId))
			->when($startDate, fn($q) => $q->where('start_date', '>=', $startDate))
			->when($endDate, fn($q) => $q->where('end_date', '<=', $endDate));
		if ($relationType) {
			$query->whereHas('relations', fn($q) => $q->where('relation_type', $relationType));
		}

		$org_id = auth()->user()->organization_id ?? null;
		$activities = $query->orderBy('start_date', 'desc')->where('deleted', 0)->where('organization_id',auth()->user()->organization_id)->get();


		$allRelations = $activities->pluck('relations')->flatten();
		[$recordsByType, $labelConfigs] = $this->preloadRelationEntities($allRelations);

		$transformed = $activities->map(function ($activity) use ($recordsByType, $labelConfigs) {
			return [
				"values" => $activity->transformToApiFormat(),
				"relatedRecords" => $this->buildRelatedRecords(
					$activity->relations,
					$recordsByType,
					$labelConfigs
				)
			];
		});

		return $this->success($transformed);
	}


	public function getEntityActivities(Request $request, $module, $entity_id)
	{
		// $allowedModules = ['Contact', 'Account', 'Lead'];
		// if (!in_array($module, $allowedModules)) {
		// 	return $this->error('Invalid module.');
		// }

		$startDate    = $request->query('start_date');
		$endDate      = $request->query('end_date');
		$relationType = $request->query('relation_type');
		$userId       = $request->query('user');

		$entityTypes = [$module];
		if ($module === 'Quotation') {
			$entityTypes[] = 'Quotaion';
		}

		$query = Activity::with('relations')
			->whereHas('relations', function ($q) use ($entityTypes, $entity_id) {
				$q->whereIn('entity_type', $entityTypes)
      ->where('entity_id', $entity_id);
			});

		if ($startDate) {
			$query->where('start_date', '>=', $startDate);
		}

		if ($endDate) {
			$query->where('end_date', '<=', $endDate);
		}

		if ($relationType) {
			$query->whereHas('relations', fn($q) => $q->where('relation_type', $relationType));
		}

		if ($userId) {
			$query->where('created_by', $userId);
		}
		$org_id = auth()->user()->organization_id;
		$query->where('organization_id', $org_id);

		$activities = $query->orderBy('created_at', 'asc')->where('deleted', 0)->get();

		$allRelations = $activities->pluck('relations')->flatten();
		[$recordsByType, $labelConfigs] = $this->preloadRelationEntities($allRelations);

		$transformed = $activities->map(function ($activity) use ($recordsByType, $labelConfigs) {
			return [
				"values" => $activity->transformToApiFormat(),
				"relatedRecords" => $this->buildRelatedRecords(
					$activity->relations,
					$recordsByType,
					$labelConfigs
				)
			];
		});

		return $this->success($transformed);
	}

	public function updateStatus(Request $request, string $id)
	{
    $data   = $request->input('data.values', []);
    $status = $data['status'] ?? null;
    $notes  = trim($data['notes'] ?? '');

    if (!$status) {
        return $this->error('Status is required.');
    }

    DB::beginTransaction();

    try {
        /* -------------------------------------------------
         * 1️⃣ Update Activity Status
         * ------------------------------------------------- */
        $record = RecordObject::make('Activity', $id, [], 'EditView');

        $fieldManager = \App\Models\FieldModelManager::make('Activity', 'EditView', true);
        $statusField  = $fieldManager->getFieldModel('status')->getFieldName();

        $record->$statusField = $status;
        $record->save();

        /* -------------------------------------------------
         * 2️⃣ Save Notes as Comment
         * ------------------------------------------------- */
        if ($notes !== '') {
            $comment = RecordObject::make('Comment', 'new', [
                'content' => $notes,
            ],'EditView');
            
            $comment->save();

            RecordObject::saveWithRelations($comment, [
                'comment_rel' => [
                    [
                        'comment_id' => $comment->id,
                        'parent_id' => $id,
                        'parent_module' => 'Activity',
                    ]
                ]
            ]);
            $orgId = auth()->user()->organization_id;
            $cacheKey = "activity:{$orgId}:{$id}:pre_summary";
            Cache::forget($cacheKey);

            Log::info('AI PreSummary cache cleared due to new comment', [
                'activity_id' => $id,
                'org_id'      => $orgId,
            ]);

        }

        /* -------------------------------------------------
         * 3️⃣ AI FOLLOW-UP DETECTION (NOTES ONLY)
         * ------------------------------------------------- */
        $createdFollowUpId = null;

        if ($notes !== '') {

            $aiResult = $this->aiDetectFollowUp($notes);

            if (!empty($aiResult['need_follow_up'])) {

                $followUpDate = !empty($aiResult['follow_up_date'])
                    ? Carbon::parse($aiResult['follow_up_date'])->startOfDay()
                    : Carbon::tomorrow()->startOfDay();

                /* -------------------------------------------------
                 * 4️⃣ SINGLE CALL — FOLLOW-UP CREATION
                 * ------------------------------------------------- */
                $createdFollowUpId = $this->createFollowUpActivity(
                    $id,
                    $followUpDate,
                    $aiResult['reason'] ?? null
                );
            }
        }

        DB::commit();

        return $this->success([
            'message'               => 'Activity status updated successfully.',
            'follow_up_activity_id' => $createdFollowUpId,
        ]);

    } catch (\Exception $e) {
        DB::rollBack();
        Log::error('updateStatus failed', ['error' => $e->getMessage()]);
        return $this->error('Failed to update activity.');
    }
}

    private function normalizeEntityType(string $entityType): string
    {
        $normalized = trim($entityType);
        $map = [
            'Quotaion' => 'Quotation',
            'quotaion' => 'Quotation',
        ];

        return $map[$normalized] ?? $normalized;
    }

    private function preloadRelationEntities(Collection $relations): array
    {
        $idsByType = [];

        foreach ($relations as $relation) {
            $entityType = $this->normalizeEntityType($relation->entity_type);
            if (empty($relation->entity_id)) {
                continue;
            }
            $idsByType[$entityType][] = $relation->entity_id;
        }

        $recordsByType = [];
        foreach ($idsByType as $entityType => $ids) {
            $ids = array_values(array_unique($ids));
            if (empty($ids)) {
                continue;
            }

            $modelClass = "\\App\\Modules\\Api\\V1\\{$entityType}\\Models\\{$entityType}";
            if (!class_exists($modelClass)) {
                continue;
            }

            $recordsByType[$entityType] = $modelClass::whereIn('id', $ids)
                ->get()
                ->keyBy('id');
        }

        $labelConfigs = [];
        if (!empty($recordsByType)) {
            $labelConfigs = DB::table('module_record_label_fields')
                ->whereIn('module_name', array_keys($recordsByType))
                ->pluck('field_name', 'module_name')
                ->toArray();
        }

        return [$recordsByType, $labelConfigs];
    }

    private function buildRelatedRecords(
        Collection $relations,
        array $recordsByType,
        array $labelConfigs,
        bool $includeValues = false
    ): Collection {
        return $relations->map(function ($relation) use ($recordsByType, $labelConfigs, $includeValues) {
            try {
                $entityType = $this->normalizeEntityType($relation->entity_type);
                $record = $recordsByType[$entityType][$relation->entity_id] ?? null;
                if (!$record || !method_exists($record, 'transformToApiFormat')) {
                    return null;
                }

                $entityData = $record->transformToApiFormat();
                $label = trim(($entityData['firstName'] ?? '') . ' ' . ($entityData['lastName'] ?? '')) ?: null;

                if (!$label && !empty($labelConfigs[$entityType])) {
                    $fields = explode(',', $labelConfigs[$entityType]);
                    $labelParts = array_filter(array_map(
                        fn($field) => $entityData[trim($field)] ?? null,
                        $fields
                    ));
                    $label = trim(implode(' ', $labelParts)) ?: null;
                }

                $payload = [
                    'id'           => $record->id,
                    'label'        => $label,
                    'relationType' => $relation->relation_type,
                    'entityType'   => $entityType,
                ];

                if ($includeValues) {
                    $payload['values'] = $entityData;
                }

                return $payload;
            } catch (\Exception $e) {
                return null;
            }
        })->filter()->values();
    }

private function createFollowUpActivity(
    string $parentActivityId,
    Carbon $followUpDate,
    ?string $reason = null
): ?string {
    try {
        $orgId  = auth()->user()->organization_id;
        $userId = auth()->user()->id;

        /* -------------------------------------------------
         * 1️⃣ Load Parent Activity (ORG SAFE)
         * ------------------------------------------------- */
        $parentActivity = DB::table('activities')
            ->where('id', $parentActivityId)
            ->where('organization_id', $orgId)
            ->where('deleted', 0)
            ->first();

        if (!$parentActivity) {
            Log::warning('FollowUp create failed: parent activity not found', [
                'activity_id' => $parentActivityId
            ]);
            return null;
        }

        /* -------------------------------------------------
         * 2️⃣ Detect Customer (Contact / Lead)
         * ------------------------------------------------- */
        $customer = DB::table('activity_relations')
            ->where('activity_id', $parentActivityId)
            ->where('organization_id', $orgId)
            ->where('deleted', 0)
            ->whereIn('entity_type', ['Contact', 'Lead'])
            ->orderByRaw("
                CASE
                    WHEN relation_type = 'contact' THEN 1
                    WHEN relation_type = 'lead' THEN 2
                    WHEN relation_type = 'participant' THEN 3
                    ELSE 4
                END
            ")
            ->first();

        if (!$customer) {
            Log::info('FollowUp skipped: no customer relation found', [
                'activity_id' => $parentActivityId
            ]);
            return null;
        }

        /* -------------------------------------------------
         * 3️⃣ DUPLICATE PREVENTION (CUSTOMER + DATE)
         * ------------------------------------------------- */
        $duplicateExists = DB::table('activities AS a')
            ->join('activity_relations AS ar', 'ar.activity_id', '=', 'a.id')
            ->where('ar.entity_id', $customer->entity_id)
            ->where('ar.entity_type', $customer->entity_type)
            ->where('a.organization_id', $orgId)
            ->where('a.start_date', $followUpDate->toDateString())
            ->where('a.deleted', 0)
            ->exists();

        if ($duplicateExists) {
            Log::info('FollowUp skipped: duplicate already exists', [
                'activity_id' => $parentActivityId,
                'entity_id'   => $customer->entity_id,
                'date'        => $followUpDate->toDateString()
            ]);
            return null;
        }

        /* -------------------------------------------------
         * 4️⃣ Build Follow-Up Activity Values
         * ------------------------------------------------- */
        $title = 'Follow-up: ' . ($parentActivity->title ?? 'Activity');

        $values = [
            'title'        => $title,
            'activityType' => 'Call', // As per confirmed flow
            'startDate'    => $followUpDate->toDateString(),
            'startTime'    => '10:00:00',
            'endDate'      => $followUpDate->toDateString(),
            'endTime'      => '10:30:00',
            'status'       => 'Scheduled',
        ];

        /* -------------------------------------------------
         * 5️⃣ Build Relations (CUSTOMER + USER)
         * ------------------------------------------------- */
        $relations = [
            [
                'relationType' => 'participant',
                'entityType'   => $customer->entity_type,
                'entityId'     => $customer->entity_id,
            ],
            [
                'relationType' => 'user',
                'entityType'   => 'User',
                'entityId'     => $userId,
            ],
        ];

        /* -------------------------------------------------
         * 6️⃣ Create Activity (RecordObject)
         * ------------------------------------------------- */
        $followUp = RecordObject::make('Activity', null, $values);
        RecordObject::saveWithRelations($followUp, $relations);

        /* -------------------------------------------------
         * 7️⃣ Optional: Save AI Reason as Comment
         * ------------------------------------------------- */
        if (!empty($reason)) {
            try {
                $comment = RecordObject::make('Comment', null, [
                    'content' => 'AI Follow-up reason: ' . $reason,
                ]);

                RecordObject::saveWithRelations($comment, [
                    [
                        'parentId'     => $followUp->id,
                        'parentModule' => 'Activity',
                    ]
                ]);
            } catch (\Exception $e) {
                Log::warning('Failed to save AI follow-up reason comment', [
                    'error' => $e->getMessage()
                ]);
            }
        }

        Log::info('FollowUp activity created successfully', [
            'follow_up_id' => $followUp->id,
            'parent_id'    => $parentActivityId,
            'date'         => $followUpDate->toDateString()
        ]);

        return $followUp->id;

    } catch (\Exception $e) {
        Log::error('createFollowUpActivity failed', [
            'error' => $e->getMessage()
        ]);
        return null;
    }
}
private function aiDetectFollowUp(string $notes): array
{
    try {
        $prompt = <<<PROMPT
You are an AI assistant for a CRM Activity module.

Analyze the NOTES below and decide whether a follow-up activity is REQUIRED.

NOTES:
{$notes}

Rules:
- Return follow-up ONLY if notes clearly indicate:
  - future discussion
  - callback request
  - waiting for confirmation
  - scheduling next step
  - unresolved action
- Do NOT suggest follow-up for:
  - informational updates
  - completed confirmations
  - status-only messages

Return ONLY valid JSON in this exact structure:

{
  "need_follow_up": true/false,
  "follow_up_date": "YYYY-MM-DD or null",
  "reason": "short explanation or null"
}
PROMPT;

        $response = OpenAI::chat()->create([
            'model' => 'gpt-4o',
            'messages' => [
                ['role' => 'system', 'content' => 'Return only valid JSON.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.2,
        ]);

        $content = trim($response->choices[0]->message->content ?? '');
        $content = preg_replace('/^```(?:json)?|```$/m', '', $content);

        $json = json_decode($content, true);

        if (!is_array($json) || !array_key_exists('need_follow_up', $json)) {
            Log::warning('AI follow-up detection invalid JSON', ['response' => $content]);
            return [
                'need_follow_up' => false,
                'follow_up_date' => null,
                'reason'         => null,
            ];
        }

        return [
            'need_follow_up' => (bool) $json['need_follow_up'],
            'follow_up_date' => $json['follow_up_date'] ?? null,
            'reason'         => $json['reason'] ?? null,
        ];

    } catch (\Exception $e) {
        Log::error('AI follow-up detection failed', ['error' => $e->getMessage()]);
        return [
            'need_follow_up' => false,
            'follow_up_date' => null,
            'reason'         => null,
        ];
    }
}
}