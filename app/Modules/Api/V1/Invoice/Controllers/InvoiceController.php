<?php

namespace App\Modules\Api\V1\Invoice\Controllers;

use App\Http\Controllers\ApiController;
use Illuminate\Http\Request;
use App\Services\CRM\RecordObject;
use Illuminate\Validation\ValidationException;
use App\Modules\Api\V1\Invoice\Models\Invoice;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Services\ModuleNumberingService;

class InvoiceController extends ApiController
{
    /**
     * Save an invoice (create or update)
     * Route: POST /api/v1/Invoice/{id}
     */
    public function save(Request $request, $id = 'new')
    {
        $module = 'Invoice';
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

            // For new invoices, generate invoice number if not provided
            if ($isNew && empty($data['invoiceNumber'])) {
                $orgId = auth()->user()->organization_id;
                $data['invoiceNumber'] = ModuleNumberingService::generateNumber('Invoice', $orgId);
            }

            // Calculate balance_due if not provided
            if (isset($data['totalAmount']) && isset($data['amountPaid'])) {
                if (!isset($data['balanceDue'])) {
                    $data['balanceDue'] = $data['totalAmount'] - ($data['amountPaid'] ?? 0);
                }
            }

            // Set default values for new columns if not provided
            if ($isNew) {
                $data['currencyCode'] = $data['currencyCode'] ?? 'USD';
                $data['exchangeRate'] = $data['exchangeRate'] ?? 1.000000;
                $data['amountPaid'] = $data['amountPaid'] ?? 0.00;
                
                if (isset($data['totalAmount'])) {
                    $data['balanceDue'] = $data['balanceDue'] ?? $data['totalAmount'];
                }
            }

            /** @var \App\Services\CRM\RecordObject|\App\Models\AtomModel $model */
            $model = RecordObject::make($module, $id, $data, 'EditView');
            Log::info("SAVE - RecordObject created with id: " . ($model->id ?? 'new'));

            if (!empty($relatedRecords)) {
                $model = RecordObject::saveWithRelations($model, $relatedRecords);
                Log::info("SAVE - Saved with related records");
            } else {
                $model->save();
            }

            Log::info("SAVE - Successfully saved invoice with id: " . $model->id);
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
     * Get invoice details
     * Route: GET /api/v1/Invoice/{id}
     */
    public function show(Request $request, string $id)
    {
        try {
            if ($id === 'new') {
                $fieldManager = \App\Models\FieldModelManager::make('Invoice', 'EditView', true);
                return $this->success([
                    'fields' => $fieldManager->getApiFormFields(),
                ]);
            }

            $viewType = 'DetailView';
            $record = RecordObject::make('Invoice', $id, [], $viewType);
            $fieldManager = \App\Models\FieldModelManager::make('Invoice', $viewType, true);

            return $this->success([
                'fields' => $fieldManager->getApiFormFields(),
                'values' => $record->transformToApiFormat(),
                'relatedRecords' => $record->getRelatedRecords(),
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to fetch invoice details for ID {$id}: " . $e->getMessage());
            return $this->error('Record not found or error: ' . $e->getMessage());
        }
    }

    /**
     * Update invoice payment
     * Route: POST /api/v1/Invoice/{id}/update-payment
     */
    public function updatePayment(Request $request, string $id)
    {
        try {
            $data = $request->input('data.values', []);
            $amountPaid = $data['amountPaid'] ?? null;

            if ($amountPaid === null) {
                return $this->error('Amount paid is required.');
            }

            $orgId = auth()->user()->organization_id;
            $invoice = Invoice::where('id', $id)
                ->where('organization_id', $orgId)
                ->where('deleted', 0)
                ->first();

            if (!$invoice) {
                return $this->error("Invoice not found for ID: {$id}");
            }

            $totalAmount = $invoice->total_amount ?? 0;
            $newBalanceDue = max(0, $totalAmount - $amountPaid);

            // Determine status based on payment
            $status = 'draft';
            if ($amountPaid >= $totalAmount) {
                $status = 'paid';
            } elseif ($amountPaid > 0) {
                $status = 'partially_paid';
            }

            $updateData = [
                'amountPaid' => $amountPaid,
                'balanceDue' => $newBalanceDue,
                'invoiceStatus' => $status,
            ];

            $record = RecordObject::make('Invoice', $id, $updateData, 'EditView');
            $record->save();

            return $this->success([
                'message' => 'Invoice payment updated successfully.',
                'id' => $record->id,
                'amountPaid' => $amountPaid,
                'balanceDue' => $newBalanceDue,
                'status' => $status,
            ]);

        } catch (\Exception $e) {
            Log::error('updatePayment failed', ['error' => $e->getMessage()]);
            return $this->error('Failed to update invoice payment.');
        }
    }

    /**
     * Update invoice status
     * Route: POST /api/v1/Invoice/{id}/update-status
     */
    public function updateStatus(Request $request, string $id)
    {
        try {
            $data = $request->input('data.values', []);
            $status = $data['invoiceStatus'] ?? $data['status'] ?? null;

            if (!$status) {
                return $this->error('Status is required.');
            }

            $orgId = auth()->user()->organization_id;
            $invoice = Invoice::where('id', $id)
                ->where('organization_id', $orgId)
                ->where('deleted', 0)
                ->first();

            if (!$invoice) {
                return $this->error("Invoice not found for ID: {$id}");
            }

            // If issuing invoice, set issued_by
            if ($status === 'issued' && !$invoice->issued_by) {
                $data['issuedBy'] = auth()->user()->id;
            }

            $record = RecordObject::make('Invoice', $id, array_merge($data, ['invoiceStatus' => $status]), 'EditView');
            $record->save();

            return $this->success([
                'message' => 'Invoice status updated successfully.',
                'id' => $record->id,
            ]);

        } catch (\Exception $e) {
            Log::error('updateStatus failed', ['error' => $e->getMessage()]);
            return $this->error('Failed to update invoice status.');
        }
    }
}
