<?php

namespace App\Modules\Api\V1\InvoiceItem\Models;

use App\Models\AtomModel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use App\Modules\Api\V1\Invoice\Models\Invoice;

class InvoiceItem extends AtomModel
{
	protected static function booted()
	{
		// Calculate line total before saving
		static::saving(function ($item) {
			$item->calculateLineTotal();
		});

		// Recalculate invoice totals and completion after saving/deleting
		static::saved(function ($invoiceItem) {
			$invoiceItem->recalculateInvoiceTotals();
			$invoiceItem->updateInvoiceCompletion();
		});

		static::deleted(function ($invoiceItem) {
			$invoiceItem->recalculateInvoiceTotals();
			$invoiceItem->updateInvoiceCompletion();
		});
	}

	/**
	 * Calculate line total for this item
	 */
	public function calculateLineTotal()
	{
		$quantity = (float) ($this->quantity ?? 0);
		$unitPrice = (float) ($this->unit_price ?? 0);
		$discountRate = (float) ($this->discount_rate ?? 0);
		$taxRate = (float) ($this->tax_rate ?? 0);

		// Calculate item subtotal
		$itemSubtotal = $quantity * $unitPrice;

		// Calculate discount amount
		$discountAmount = ($itemSubtotal * $discountRate) / 100;
		$itemAfterDiscount = $itemSubtotal - $discountAmount;

		// Calculate tax amount (on amount after discount)
		$taxAmount = ($itemAfterDiscount * $taxRate) / 100;

		// Calculate line total
		$lineTotal = $itemAfterDiscount + $taxAmount;

		// Update item fields
		$this->discount_amount = round($discountAmount, 2);
		$this->tax_amount = round($taxAmount, 2);
		$this->line_total = round($lineTotal, 2);
	}

	/**
	 * Recalculate invoice totals from all items
	 */
	public function recalculateInvoiceTotals()
	{
		if (!$this->invoice_id) {
			return;
		}

		$invoice = Invoice::find($this->invoice_id);
		if (!$invoice) {
			return;
		}

		// Get all items for this invoice
		$items = self::where('invoice_id', $this->invoice_id)
			->where('organization_id', $invoice->organization_id)
			->where('deleted', 0)
			->get();

		// Calculate totals
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

		// Get shipping and adjustment from invoice
		$shippingAmount = (float) ($invoice->shipping_amount ?? 0);
		$adjustmentAmount = (float) ($invoice->adjustment_amount ?? 0);

		// Calculate total
		$totalAmount = $subtotal - $totalDiscount + $totalTax + $shippingAmount + $adjustmentAmount;

		// Calculate balance due
		$amountPaid = (float) ($invoice->amount_paid ?? 0);
		$balanceDue = max(0, $totalAmount - $amountPaid);

		// Update invoice totals
		$invoice->subtotal = round($subtotal, 2);
		$invoice->discount_amount = round($totalDiscount, 2);
		$invoice->tax_amount = round($totalTax, 2);
		$invoice->total_amount = round($totalAmount, 2);
		$invoice->balance_due = round($balanceDue, 2);
		$invoice->saveQuietly();

		Log::info("Invoice totals recalculated", [
			'invoice_id' => $invoice->id,
			'subtotal' => $invoice->subtotal,
			'discount_amount' => $invoice->discount_amount,
			'tax_amount' => $invoice->tax_amount,
			'total_amount' => $invoice->total_amount,
			'balance_due' => $invoice->balance_due,
		]);
	}

	public function updateInvoiceCompletion()
	{
		$invoice = Invoice::find($this->invoice_id);

		if (!$invoice) {
			Log::channel('invoice')->info('No parent invoice found', [
				'invoice_item_id' => $this->id
			]);
			return;
		}

		// Only update completion if the column exists
		if (!\Schema::hasColumn($invoice->getTable(), 'completion_percentage')) {
			return;
		}

		$totalItems = $invoice->items()->count();

		if ($totalItems === 0) {
			$invoice->completion_percentage = 0;
			if (isset($invoice->invoice_status)) {
				$invoice->invoice_status = 'draft';
			}
		} else {
			// Check if status column exists in invoice_items table
			$hasStatusColumn = \Schema::hasColumn('invoice_items', 'status');
			
			if ($hasStatusColumn) {
				$completedItems = $invoice->items()
				     ->where('status', 'completed')
				     ->count();

				$percentage = (int) round(($completedItems / $totalItems) * 100);
				$invoice->completion_percentage = $percentage;

				if ($percentage === 100 && isset($invoice->invoice_status)) {
					$invoice->invoice_status = 'completed';
				} elseif ($completedItems > 0 && isset($invoice->invoice_status)) {
					$invoice->invoice_status = 'in_progress';
				} elseif (isset($invoice->invoice_status)) {
					$invoice->invoice_status = 'draft';
				}
			} else {
				// If no status column, assume all items are completed
				$invoice->completion_percentage = 100;
				if (isset($invoice->invoice_status)) {
					$invoice->invoice_status = 'issued';
				}
			}
		}

		$invoice->save();
	}

	public function getModuleName(): string
	{
		return 'InvoiceItem';
	}
}

