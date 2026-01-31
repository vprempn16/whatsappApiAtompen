<?php

namespace App\Modules\Api\V1\Checklist\Models;

use App\Models\AtomModel;
use App\Modules\Api\V1\Invoice\Models\Invoice;

class Checklist extends AtomModel
{
    protected static function booted()
    {
        static::saved(function ($checklist) {
            $checklist->updateInvoiceCompletion();
        });

        static::deleted(function ($checklist) {
            $checklist->updateInvoiceCompletion();
        });
    }

    public function invoice()
    {
        return $this->belongsTo(
            Invoice::class,
            'record_id', // checklist.record_id → invoice.id
            'id'
        );
    }

    public function items()
    {
        return $this->hasMany(
            \App\Modules\Api\V1\ChecklistItem\Models\ChecklistItem::class,
            'checklist_id'
        );
    }

    public function updateInvoiceCompletion()
    {
        $invoice = $this->invoice;

        if (!$invoice) {
            return;
        }

        $totalChecklists = $invoice->checklists()->count();

        if ($totalChecklists === 0) {
            $invoice->completion_percentage = 0;
            $invoice->status = 'draft';
        } else {
            $completedChecklists = $invoice->checklists()
                ->where('status', 'completed')
                ->count();

            $percentage = (int) round(($completedChecklists / $totalChecklists) * 100);
            $invoice->completion_percentage = $percentage;

            if ($percentage === 100) {
                $invoice->status = 'completed';
            } elseif ($completedChecklists > 0) {
                $invoice->status = 'in_progress';
            } else {
                $invoice->status = 'draft';
            }
        }

        if ($invoice->isDirty(['completion_percentage', 'status'])) {
            $invoice->saveQuietly();
        }
    }

    public function getModuleName(): string
    {
        return 'Checklist';
    }
}
