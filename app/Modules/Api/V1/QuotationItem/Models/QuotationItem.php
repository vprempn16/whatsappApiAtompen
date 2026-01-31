<?php

namespace App\Modules\Api\V1\QuotationItem\Models;

use App\Models\AtomModel;
use App\Modules\Api\V1\Quotation\Models\Quotation;
use Illuminate\Support\Facades\Log;

class QuotationItem extends AtomModel
{
    protected $table = 'quotation_items';

    protected static function booted()
    {
        // Calculate line total before saving
        static::saving(function ($item) {
            $item->calculateLineTotal();
        });

        // Recalculate quotation totals after saving/deleting
        static::saved(function ($item) {
            $item->recalculateQuotationTotals();
        });

        static::deleted(function ($item) {
            $item->recalculateQuotationTotals();
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
     * Recalculate quotation totals from all items
     */
    public function recalculateQuotationTotals()
    {
        if (!$this->quotation_id) {
            return;
        }

        $quotation = Quotation::find($this->quotation_id);
        if (!$quotation) {
            return;
        }

        // Get all items for this quotation
        $items = self::where('quotation_id', $this->quotation_id)
            ->where('organization_id', $quotation->organization_id)
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

        // Get shipping and adjustment from quotation
        $shippingAmount = (float) ($quotation->shipping_amount ?? 0);
        $adjustmentAmount = (float) ($quotation->adjustment_amount ?? 0);

        // Calculate total
        $totalAmount = $subtotal - $totalDiscount + $totalTax + $shippingAmount + $adjustmentAmount;

        // Update quotation totals
        $quotation->subtotal = round($subtotal, 2);
        $quotation->discount_amount = round($totalDiscount, 2);
        $quotation->tax_amount = round($totalTax, 2);
        $quotation->total_amount = round($totalAmount, 2);
        $quotation->saveQuietly();

        Log::info("Quotation totals recalculated", [
            'quotation_id' => $quotation->id,
            'subtotal' => $quotation->subtotal,
            'discount_amount' => $quotation->discount_amount,
            'tax_amount' => $quotation->tax_amount,
            'total_amount' => $quotation->total_amount,
        ]);
    }
}
