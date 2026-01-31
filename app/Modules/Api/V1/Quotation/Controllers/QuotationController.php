<?php

namespace App\Modules\Api\V1\Quotation\Controllers;

use App\Http\Controllers\ApiController;
use Illuminate\Http\Request;
use App\Services\CRM\RecordObject;
use Illuminate\Validation\ValidationException;
use App\Modules\Api\V1\Quotation\Models\Quotation;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Services\ModuleNumberingService;
use App\Modules\Api\V1\Invoice\Models\Invoice;
use App\Modules\Api\V1\InvoiceItem\Models\InvoiceItem;
use App\Modules\Api\V1\QuotationItem\Models\QuotationItem;
use App\Services\AuditLogService;

class QuotationController extends ApiController
{
    /**
     * Save a quotation (create or update)
     * Route: POST /api/v1/Quotation/{id}
     */
    public function save(Request $request, $id = 'new')
    {
        $module = 'Quotation';
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

            if (empty($data)) {
                Log::warning("SAVE - No data received for saving");
                return $this->error('No data received for saving');
            }

            /** @var \App\Services\CRM\RecordObject|\App\Models\AtomModel $model */
            $model = RecordObject::make($module, $id, $data, 'EditView');
            Log::info("SAVE - RecordObject created with id: " . $model->id);

            if (!empty($relatedRecords)) {
                $model = RecordObject::saveWithRelations($model, $relatedRecords);
                Log::info("SAVE - Saved with related records");
            } else {
                $model->save();
            }

            Log::info("SAVE - Successfully saved quotation with id: " . $model->id);
            return $this->success(['id' => $model->id]);

        } catch (ValidationException $e) {
            Log::error("SAVE - ValidationException: " . $e->getMessage());
            return $this->error($e->getMessage());
        } catch (\Exception $e) {
            Log::error("SAVE - Exception: " . $e->getMessage());
            return $this->error($e->getMessage());
        }
    }

    /**
     * Get quotation details
     * Route: GET /api/v1/Quotation/{id}
     */
    public function show(Request $request, string $id)
    {
        try {
            if ($id === 'new') {
                $fieldManager = \App\Models\FieldModelManager::make('Quotation', 'EditView', true);
                return $this->success([
                    'fields' => $fieldManager->getApiFormFields(),
                ]);
            }

            $viewType = 'DetailView';
            $record = RecordObject::make('Quotation', $id, [], $viewType);
            $fieldManager = \App\Models\FieldModelManager::make('Quotation', $viewType, true);

            return $this->success([
                'fields' => $fieldManager->getApiFormFields(),
                'values' => $record->transformToApiFormat(),
                'relatedRecords' => $record->getRelatedRecords(),
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to fetch quotation details for ID {$id}: " . $e->getMessage());
            return $this->error('Record not found or error: ' . $e->getMessage());
        }
    }

    /**
     * List quotations
     * Route: GET /api/v1/Quotation
     */
    public function index(Request $request)
    {
        try {
            $orgId = auth()->user()->organization_id;
            $status = $request->query('status');
            $customerId = $request->query('customer_id');
            $perPage = (int) $request->query('per_page', 20);
            $page = (int) $request->query('page', 1);

            $query = Quotation::where('organization_id', $orgId)
                ->where('deleted', 0);

            if ($status) {
                $query->where('quotation_status', $status);
            }

            if ($customerId) {
                $query->where('customer_id', $customerId);
            }

            // Use pagination to prevent memory issues with large datasets
            $quotations = $query->orderBy('created_at', 'desc')->paginate($perPage, ['*'], 'page', $page);

            $transformed = $quotations->getCollection()->map(function ($quotation) {
                return [
                    'values' => $quotation->transformToApiFormat(),
                ];
            });

            return $this->success([
                'list' => $transformed,
                'meta' => [
                    'current_page' => $quotations->currentPage(),
                    'last_page'    => $quotations->lastPage(),
                    'per_page'     => $quotations->perPage(),
                    'total'        => $quotations->total(),
                ],
                'links' => [
                    'first' => $quotations->url(1),
                    'last'  => $quotations->url($quotations->lastPage()),
                    'prev'  => $quotations->previousPageUrl(),
                    'next'  => $quotations->nextPageUrl(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to fetch quotations", ['error' => $e->getMessage()]);
            return $this->error("Failed to fetch quotations. Please try again.");
        }
    }

    /**
     * Convert quotation to invoice
     * Route: POST /api/v1/Quotation/{id}/convert-to-invoice
     */
    public function convertToInvoice(Request $request, string $id)
    {
        DB::beginTransaction();
        try {
            $orgId = auth()->user()->organization_id;
            $userId = auth()->user()->id;

            // Load quotation
            $quotation = Quotation::where('id', $id)
                ->where('organization_id', $orgId)
                ->where('deleted', 0)
                ->first();

            if (!$quotation) {
                return $this->error("Quotation not found for ID: {$id}");
            }

            // Check if already converted
            if ($quotation->converted_to_invoice_id) {
                return $this->error("This quotation has already been converted to invoice");
            }

            // Generate invoice number
            $invoiceNumber = ModuleNumberingService::generateNumber('Invoice', $orgId);

            // Prepare invoice data
            $invoiceData = [
                'invoiceNumber' => $invoiceNumber,
                'customerId' => $quotation->customer_id,
                'invoiceDate' => now()->toDateString(),
                'dueDate' => $request->input('due_date') ?? now()->addDays(30)->toDateString(),
                'invoiceStatus' => 'draft',
                'subtotal' => $quotation->subtotal ?? 0.00,
                'discountAmount' => $quotation->discount_amount ?? 0.00,
                'taxAmount' => $quotation->tax_amount ?? 0.00,
                'shippingAmount' => $quotation->shipping_amount ?? 0.00,
                'adjustmentAmount' => $quotation->adjustment_amount ?? 0.00,
                'totalAmount' => $quotation->total_amount ?? 0.00,
                'amountPaid' => 0.00,
                'balanceDue' => $quotation->total_amount ?? 0.00,
                'currencyCode' => $request->input('currency_code') ?? 'USD',
                'exchangeRate' => $request->input('exchange_rate') ?? 1.000000,
                'taxType' => $quotation->tax_type,
                'taxRegistrationNumber' => $request->input('tax_registration_number'),
                'quotationId' => $quotation->id,
                'notes' => $quotation->notes,
                'termsAndConditions' => $quotation->terms_and_conditions,
            ];

            // Create invoice
            $invoice = RecordObject::make('Invoice', null, $invoiceData, 'EditView');
            $invoice->save();

            Log::info("Invoice created from quotation", [
                'quotation_id' => $id,
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoiceNumber,
            ]);

            // Load quotation items
            $quotationItems = QuotationItem::where('quotation_id', $id)
                ->where('organization_id', $orgId)
                ->where('deleted', 0)
                ->orderBy('sort_order', 'asc')
                ->get();

            // Convert quotation items to invoice items
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

            // Update quotation to mark as converted
            $quotation->converted_to_invoice_id = $invoice->id;
            $quotation->quotation_status = 'accepted';
            $quotation->save();

            // Create transfer audit log
            $auditService = new AuditLogService();
            $auditService->logTransfer(
                'Quotation',
                $quotation->id,
                'Invoice',
                $invoice->id,
                [
                    'quotation_number' => $quotation->quotation_number ?? null,
                    'invoice_number' => $invoiceNumber,
                ],
                $orgId,
                $userId
            );

            DB::commit();

            // Load invoice with items for response
            $invoice->refresh();
            $invoiceData = $invoice->transformToApiFormat();

            $items = DB::table('invoice_items')
                ->where('invoice_id', $invoice->id)
                ->where('organization_id', $orgId)
                ->where('deleted', 0)
                ->orderBy('sort_order', 'asc')
                ->get()
                ->map(function ($item) {
                    $itemModel = RecordObject::make('InvoiceItem', $item->id);
                    return $itemModel->transformToApiFormat();
                });

            Log::info("Quotation converted to invoice successfully", [
                'quotation_id' => $id,
                'invoice_id' => $invoice->id,
            ]);

            return $this->success([
                'invoice' => [
                    'values' => $invoiceData,
                    'items' => $items,
                ],
                'message' => 'Quotation converted to invoice successfully',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to convert quotation to invoice: " . $e->getMessage());
            return $this->error("Failed to convert quotation to invoice: " . $e->getMessage());
        }
    }

    /**
     * Update quotation status
     * Route: POST /api/v1/Quotation/{id}/update-status
     */
    public function updateStatus(Request $request, string $id)
    {
        try {
            $data = $request->input('data.values', []);
            $status = $data['quotationStatus'] ?? $data['status'] ?? null;

            if (!$status) {
                return $this->error('Status is required.');
            }

            $orgId = auth()->user()->organization_id;
            $quotation = Quotation::where('id', $id)
                ->where('organization_id', $orgId)
                ->where('deleted', 0)
                ->first();

            if (!$quotation) {
                return $this->error("Quotation not found for ID: {$id}");
            }

            $record = RecordObject::make('Quotation', $id, ['quotationStatus' => $status], 'EditView');
            $record->save();

            return $this->success([
                'message' => 'Quotation status updated successfully.',
                'id' => $record->id,
            ]);

        } catch (\Exception $e) {
            Log::error('updateStatus failed', ['error' => $e->getMessage()]);
            return $this->error('Failed to update quotation status.');
        }
    }
}
