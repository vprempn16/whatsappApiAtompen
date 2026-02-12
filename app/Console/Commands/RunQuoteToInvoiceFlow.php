<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Services\CRM\RecordObject;
use App\Services\ModuleNumberingService;
use App\Modules\Api\V1\Quotation\Models\Quotation;
use App\Modules\Api\V1\Invoice\Models\Invoice;
use App\Modules\Api\V1\User\Models\User;

class RunQuoteToInvoiceFlow extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'quote:to-invoice {--contact-id=639b1499-fa94-465a-b98f-1f0e0040e872}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run the quote to invoice flow with existing contact';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $customerId = $this->option('contact-id');
        
        // Set up a test user FIRST, before any other operations
        // Use withoutGlobalScopes to avoid triggering FieldModelManager before auth
        $user = User::withoutGlobalScopes()->first();
        if (!$user) {
            $this->error('❌ No user found. Please create a user first.');
            return 1;
        }

        // Set authenticated user BEFORE any RecordObject calls
        // Use loginUsingId which works with any user ID
        try {
            auth()->loginUsingId($user->id);
        } catch (\Exception $e) {
            // Fallback: set user directly on default guard using reflection
            $guard = auth()->guard();
            if (property_exists($guard, 'user')) {
                $reflection = new \ReflectionClass($guard);
                $property = $reflection->getProperty('user');
                $property->setAccessible(true);
                $property->setValue($guard, $user);
            }
        }
        $orgId = $user->organization_id;

        $this->info("✅ Authenticated as: {$user->first_name} {$user->last_name} (Org ID: {$orgId})\n");

        // Verify contact exists
        $contact = \App\Modules\Api\V1\Contact\Models\Contact::where('id', $customerId)
            ->where('organization_id', $orgId)
            ->where('deleted', 0)
            ->first();

        if (!$contact) {
            $this->error("❌ Contact not found with ID: {$customerId}");
            return 1;
        }

        $this->info("✅ Using existing contact: {$contact->first_name} {$contact->last_name} (ID: {$contact->id})\n");

        // Create Quotation
        $this->info("📄 STEP 1: Creating a Quotation with Items...");

        $quotationData = [
            'customerId' => $customerId,
            'quotationDate' => now()->toDateString(),
            'validUntil' => now()->addDays(30)->toDateString(),
            'quotationStatus' => 'draft',
            'subtotal' => 0,
            'discountAmount' => 0,
            'taxAmount' => 0,
            'shippingAmount' => 0,
            'adjustmentAmount' => 0,
            'totalAmount' => 0,
            'taxIncluded' => 0,
            'version' => 1,
            'notes' => 'This is a test quotation for product sales.',
            'termsAndConditions' => 'Payment terms: Net 30 days. Delivery: 2-3 weeks.',
        ];

        $quotation = RecordObject::make('Quotation', null, $quotationData, 'EditView');
        $quotation->save();

        $this->info("✅ Created quotation: {$quotation->identifier} (ID: {$quotation->id})");

        // Add quotation items
        $items = [
            [
                'quotationId' => $quotation->id,
                'description' => 'Premium Widget A - High Quality',
                'quantity' => 5,
                'unitPrice' => 100.00,
                'discountRate' => 10.00,
                'taxRate' => 8.00,
                'sortOrder' => 1,
            ],
            [
                'quotationId' => $quotation->id,
                'description' => 'Standard Widget B - Standard Quality',
                'quantity' => 3,
                'unitPrice' => 75.00,
                'discountRate' => 5.00,
                'taxRate' => 8.00,
                'sortOrder' => 2,
            ],
        ];

        $subtotal = 0;
        $totalDiscount = 0;
        $totalTax = 0;

        foreach ($items as $itemData) {
            $quantity = $itemData['quantity'];
            $unitPrice = $itemData['unitPrice'];
            $discountRate = $itemData['discountRate'] ?? 0;
            $taxRate = $itemData['taxRate'] ?? 0;
            
            $itemSubtotal = $quantity * $unitPrice;
            $discountAmount = ($itemSubtotal * $discountRate) / 100;
            $itemAfterDiscount = $itemSubtotal - $discountAmount;
            $taxAmount = ($itemAfterDiscount * $taxRate) / 100;
            $lineTotal = $itemAfterDiscount + $taxAmount;
            
            $itemData['discountAmount'] = round($discountAmount, 2);
            $itemData['taxAmount'] = round($taxAmount, 2);
            $itemData['lineTotal'] = round($lineTotal, 2);
            
            $subtotal += $itemSubtotal;
            $totalDiscount += $discountAmount;
            $totalTax += $taxAmount;
            
            $quotationItem = RecordObject::make('QuotationItem', null, $itemData, 'EditView');
            $quotationItem->save();
            $this->line("  ✅ Added item: {$itemData['description']} - Qty: {$quantity} x \${$unitPrice}");
        }

        // Update quotation totals
        $shippingAmount = 50.00;
        $adjustmentAmount = 0.00;
        $totalAmount = $subtotal - $totalDiscount + $totalTax + $shippingAmount + $adjustmentAmount;

        $quotation->subtotal = round($subtotal, 2);
        $quotation->discount_amount = round($totalDiscount, 2);
        $quotation->tax_amount = round($totalTax, 2);
        $quotation->shipping_amount = $shippingAmount;
        $quotation->adjustment_amount = $adjustmentAmount;
        $quotation->total_amount = round($totalAmount, 2);
        $quotation->save();

        $this->info("✅ Quotation totals updated:");
        $this->line("   Subtotal: \${$quotation->subtotal}");
        $this->line("   Discount: \${$quotation->discount_amount}");
        $this->line("   Tax: \${$quotation->tax_amount}");
        $this->line("   Shipping: \${$quotation->shipping_amount}");
        $this->line("   Total: \${$quotation->total_amount}\n");

        // Update Quotation Status
        $this->info("📊 STEP 2: Updating Quotation Status...");
        $quotation->quotation_status = 'sent';
        $quotation->save();
        $this->info("✅ Quotation status updated to: sent");
        
        $quotation->quotation_status = 'accepted';
        $quotation->save();
        $this->info("✅ Quotation status updated to: accepted\n");

        // Convert Quotation to Invoice
        $this->info("🔄 STEP 3: Converting Quotation to Invoice...");

        DB::beginTransaction();
        try {
            $invoiceNumber = ModuleNumberingService::generateNumber('Invoice', $orgId);
            
            $invoiceData = [
                'invoiceNumber' => $invoiceNumber,
                'customerId' => $quotation->customer_id,
                'invoiceDate' => now()->toDateString(),
                'dueDate' => now()->addDays(30)->toDateString(),
                'invoiceStatus' => 'draft',
                'subtotal' => $quotation->subtotal,
                'discountAmount' => $quotation->discount_amount,
                'taxAmount' => $quotation->tax_amount,
                'shippingAmount' => $quotation->shipping_amount,
                'adjustmentAmount' => $quotation->adjustment_amount,
                'totalAmount' => $quotation->total_amount,
                'amountPaid' => 0.00,
                'balanceDue' => $quotation->total_amount,
                'currencyCode' => 'USD',
                'exchangeRate' => 1.000000,
                'taxType' => $quotation->tax_type,
                'quotationId' => $quotation->id,
                'notes' => $quotation->notes,
                'termsAndConditions' => $quotation->terms_and_conditions,
            ];
            
            $invoice = RecordObject::make('Invoice', null, $invoiceData, 'EditView');
            $invoice->save();
            $this->info("✅ Created invoice: {$invoice->invoice_number} (ID: {$invoice->id})");
            
            $quotationItems = \App\Modules\Api\V1\QuotationItem\Models\QuotationItem::where('quotation_id', $quotation->id)
                ->where('organization_id', $orgId)
                ->where('deleted', 0)
                ->get();
            
            foreach ($quotationItems as $qItem) {
                $invoiceItemData = [
                    'invoiceId' => $invoice->id,
                    'productId' => $qItem->product_id,
                    'description' => $qItem->description,
                    'quantity' => $qItem->quantity,
                    'unitPrice' => $qItem->unit_price,
                    'discountRate' => $qItem->discount_rate ?? 0.00,
                    'discountAmount' => $qItem->discount_amount ?? 0.00,
                    'taxRate' => $qItem->tax_rate ?? 0.00,
                    'taxAmount' => $qItem->tax_amount ?? 0.00,
                    'lineTotal' => $qItem->line_total,
                    'sortOrder' => $qItem->sort_order ?? 0,
                ];
                
                $invoiceItem = RecordObject::make('InvoiceItem', null, $invoiceItemData, 'EditView');
                $invoiceItem->save();
            }
            
            $this->info("✅ Converted {$quotationItems->count()} items to invoice items");
            
            $quotation->converted_to_invoice_id = $invoice->id;
            $quotation->quotation_status = 'accepted';
            $quotation->save();
            
            DB::commit();
            $this->info("✅ Quotation marked as converted");
            $this->info("✅ Invoice created successfully!");
            $this->line("   Invoice Number: {$invoice->invoice_number}");
            $this->line("   Total Amount: \${$invoice->total_amount}");
            $this->line("   Balance Due: \${$invoice->balance_due}\n");
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("❌ Error converting quotation to invoice: {$e->getMessage()}");
            return 1;
        }

        // Update Invoice Payment
        $this->info("💰 STEP 4: Updating Invoice Status and Payment...");

        $invoice->invoice_status = 'issued';
        $invoice->issued_by = $user->id;
        $invoice->save();
        $this->info("✅ Invoice status updated to: issued");

        $partialPayment = $invoice->total_amount * 0.5;
        $invoice->amount_paid = $partialPayment;
        $invoice->balance_due = $invoice->total_amount - $partialPayment;
        $invoice->invoice_status = 'partially_paid';
        $invoice->save();
        $this->info("✅ Partial payment recorded: \${$partialPayment}");
        $this->line("   Amount Paid: \${$invoice->amount_paid}");
        $this->line("   Balance Due: \${$invoice->balance_due}");

        $invoice->amount_paid = $invoice->total_amount;
        $invoice->balance_due = 0.00;
        $invoice->invoice_status = 'paid';
        $invoice->save();
        $this->info("✅ Full payment recorded");
        $this->line("   Amount Paid: \${$invoice->amount_paid}");
        $this->line("   Balance Due: \${$invoice->balance_due}");
        $this->line("   Status: {$invoice->invoice_status}\n");

        // SUMMARY
        $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $this->info("📋 SUMMARY");
        $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $this->line("✅ Contact: {$contact->first_name} {$contact->last_name} (ID: {$contact->id})");
        $this->line("✅ Quotation Created: {$quotation->identifier}");
        $this->line("   Status: {$quotation->quotation_status}");
        $this->line("   Total: \${$quotation->total_amount}");
        $this->line("✅ Invoice Created: {$invoice->invoice_number}");
        $this->line("   Status: {$invoice->invoice_status}");
        $this->line("   Total: \${$invoice->total_amount}");
        $this->line("   Paid: \${$invoice->amount_paid}");
        $this->line("   Balance: \${$invoice->balance_due}");
        $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $this->info("\n🎉 Real-world quote to invoice flow completed successfully!\n");

        return 0;
    }
}
