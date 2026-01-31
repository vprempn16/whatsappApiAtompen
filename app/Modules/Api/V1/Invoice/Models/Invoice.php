<?php

namespace App\Modules\Api\V1\Invoice\Models;

use App\Models\AtomModel;
use Illuminate\Support\Facades\Log;
use App\Services\ModuleNumberingService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Auth;

class Invoice extends AtomModel
{
	protected static function booted()
	{
		// Generate invoice number before saving if not set
		static::creating(function ($invoice) {
			if (empty($invoice->invoice_number)) {
				$orgId = Auth::user()->organization_id ?? null;
				if ($orgId) {
					$invoice->invoice_number = ModuleNumberingService::generateNumber('Invoice', $orgId);
				}
			}
		});

		// Recalculate balance due when amount_paid or total_amount changes
		static::saving(function ($invoice) {
			if ($invoice->isDirty(['amount_paid', 'total_amount'])) {
				$totalAmount = (float) ($invoice->total_amount ?? 0);
				$amountPaid = (float) ($invoice->amount_paid ?? 0);
				$invoice->balance_due = max(0, $totalAmount - $amountPaid);
			}
		});

		static::saved(function ($invoice) {
			$invoice->recalculateCompletion();
		});
	}

	/**
	 * Recalculate completion percentage and status based on items.
	 * Note: completion_percentage column may not exist in all databases
	 */
	public function recalculateCompletion()
	{
		// Check if completion_percentage column exists
		if (!\Schema::hasColumn($this->getTable(), 'completion_percentage')) {
			// Skip if column doesn't exist
			return;
		}

		// 🚨 If already completed, lock it as completed
		if (isset($this->status) && $this->status === 'completed') {
			$this->completion_percentage = 100;

			Log::channel('invoice')->info('Invoice already completed → skipping recalculation', [
				'invoice_id' => $this->id,
				'status'     => $this->status ?? null,
				'completion' => $this->completion_percentage,
			]);

			$this->saveQuietly();
			return;
		}

		$totalItems = $this->items()->count();

		Log::channel('invoice')->info('Recalculating invoice completion', [
			'invoice_id'  => $this->id,
			'total_items' => $totalItems,
		]);

		if ($totalItems === 0) {
			$this->completion_percentage = 0;
			if (isset($this->invoice_status)) {
				$this->invoice_status = 'draft';
			}

			Log::channel('invoice')->info('No items found → reset invoice', [
				'invoice_id' => $this->id,
				'status'     => $this->invoice_status ?? null,
				'completion' => $this->completion_percentage,
			]);
		} else {
			// Check if status column exists in invoice_items table
			$hasStatusColumn = \Schema::hasColumn('invoice_items', 'status');
			$completedItems = 0;
			
			if ($hasStatusColumn) {
				$completedItems = $this->items()
				  ->where('status', 'completed')
				  ->count();
				
				$percentage = (int) round(($completedItems / $totalItems) * 100);
				$this->completion_percentage = $percentage;

				if ($percentage === 100 && isset($this->invoice_status)) {
					$this->invoice_status = 'completed';
				} elseif ($completedItems > 0 && isset($this->invoice_status)) {
					$this->invoice_status = 'in_progress';
				}
			} else {
				// If no status column, assume all items are completed when they exist
				$this->completion_percentage = 100;
				if (isset($this->invoice_status)) {
					$this->invoice_status = 'issued';
				}
			}

			Log::channel('invoice')->info('Invoice updated from recalculation', [
				'invoice_id'      => $this->id,
				'completed_items' => $completedItems,
				'total_items'     => $totalItems,
				'completion'      => $this->completion_percentage,
				'status'          => $this->invoice_status ?? null,
			]);
		}

		$this->saveQuietly();
	}

	public function checklists()
	{
		return $this->hasMany(
			\App\Modules\Api\V1\Checklist\Models\Checklist::class,
			'record_id', // foreign key on checklist table
			'id'         // local key on invoices
		)->where('module', 'Invoice'); // so only checklists linked to Invoice are fetched
	}

	public function items()
	{
		return $this->hasMany(
			\App\Modules\Api\V1\InvoiceItem\Models\InvoiceItem::class,
			'invoice_id'
		);
	}

	/**
	 * Recalculate invoice totals from items
	 * This can be called manually or will be triggered by InvoiceItem model
	 */
	public function recalculateTotals()
	{
		$items = \App\Modules\Api\V1\InvoiceItem\Models\InvoiceItem::where('invoice_id', $this->id)
			->where('organization_id', $this->organization_id)
			->where('deleted', 0)
			->get();

		$subtotal = 0;
		$totalDiscount = 0;
		$totalTax = 0;

		foreach ($items as $item) {
			$quantity = (float) ($item->quantity ?? 0);
			$unitPrice = (float) ($item->unit_price ?? 0);
			$itemSubtotal = $quantity * $unitPrice;
			
			$subtotal += $itemSubtotal;
			$totalDiscount += (float) ($item->discount_amount ?? 0);
			$totalTax += (float) ($item->tax_amount ?? 0);
		}

		$shippingAmount = (float) ($this->shipping_amount ?? 0);
		$adjustmentAmount = (float) ($this->adjustment_amount ?? 0);
		$totalAmount = $subtotal - $totalDiscount + $totalTax + $shippingAmount + $adjustmentAmount;

		$amountPaid = (float) ($this->amount_paid ?? 0);
		$balanceDue = max(0, $totalAmount - $amountPaid);

		$this->subtotal = round($subtotal, 2);
		$this->discount_amount = round($totalDiscount, 2);
		$this->tax_amount = round($totalTax, 2);
		$this->total_amount = round($totalAmount, 2);
		$this->balance_due = round($balanceDue, 2);
		$this->saveQuietly();

		Log::info("Invoice totals recalculated", [
			'invoice_id' => $this->id,
			'subtotal' => $this->subtotal,
			'discount_amount' => $this->discount_amount,
			'tax_amount' => $this->tax_amount,
			'total_amount' => $this->total_amount,
			'balance_due' => $this->balance_due,
		]);
	}
}

