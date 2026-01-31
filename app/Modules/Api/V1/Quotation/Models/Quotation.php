<?php

namespace App\Modules\Api\V1\Quotation\Models;

use App\Models\AtomModel;
use Illuminate\Support\Facades\Log;

class Quotation extends AtomModel
{
    protected $table = 'quotations';

    /**
     * Recalculate quotation totals from items
     * This can be called manually or will be triggered by QuotationItem model
     */
    public function recalculateTotals()
    {
        $items = \App\Modules\Api\V1\QuotationItem\Models\QuotationItem::where('quotation_id', $this->id)
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

        $this->subtotal = round($subtotal, 2);
        $this->discount_amount = round($totalDiscount, 2);
        $this->tax_amount = round($totalTax, 2);
        $this->total_amount = round($totalAmount, 2);
        $this->saveQuietly();

        Log::info("Quotation totals recalculated", [
            'quotation_id' => $this->id,
            'subtotal' => $this->subtotal,
            'discount_amount' => $this->discount_amount,
            'tax_amount' => $this->tax_amount,
            'total_amount' => $this->total_amount,
        ]);
    }
}
