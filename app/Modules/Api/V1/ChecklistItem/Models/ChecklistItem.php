<?php

namespace App\Modules\Api\V1\ChecklistItem\Models;

use App\Models\AtomModel;

class ChecklistItem extends AtomModel
{
	protected static function booted()
	{
		static::saved(function ($item) {
			$item->updateChecklistCompletion();
		});

		static::deleted(function ($item) {
			$item->updateChecklistCompletion();
		});
	}

	public function checklist()
	{
		return $this->belongsTo(
			\App\Modules\Api\V1\Checklist\Models\Checklist::class,
			'checklist_id'
		);
	}

	public function updateChecklistCompletion()
	{
		$checklist = $this->checklist;

		if (!$checklist) {
			return;
		}

		$totalItems = $checklist->items()->count();

		if ($totalItems === 0) {
			$checklist->completion_percentage = 0;
			$checklist->status = 'pending';
		} else {
			$doneItems = $checklist->items()
			  ->where('status', 'done')
			  ->count();

			$percentage = (int) round(($doneItems / $totalItems) * 100);
			$checklist->completion_percentage = $percentage;

			if ($percentage === 100) {
				$checklist->status = 'completed';
			} elseif ($doneItems > 0) {
				$checklist->status = 'in_progress';
			} else {
				$checklist->status = 'pending';
			}
		}

		if ($checklist->isDirty(['completion_percentage', 'status'])) {
			$checklist->saveQuietly();
		}
	}

	public function getModuleName(): string
	{
		return 'ChecklistItem';
	}
}
