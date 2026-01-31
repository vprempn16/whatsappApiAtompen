<?php

namespace App\Modules\Api\V1\Lead\Controllers;

use App\Http\Controllers\ApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Modules\Api\V1\Contact\Models\Contact;
use App\Modules\Api\V1\Lead\Models\Lead;
use App\Services\AuditLogService;

class LeadController extends ApiController
{
	public function transformToContact(Request $request, $id)
	{
		return DB::transaction(function () use ($id) {
			$organizationId = auth()->user()->organization_id;

			$lead = Lead::where('id', $id)
				->where('deleted', 0)
				->where('organization_id', $organizationId)
				->where('is_converted', 0)
				->first();

			if (!$lead) {
				return $this->error('Lead not found or already the record will be converted.');
			}

			// Check if already converted
			if (Contact::where('converted_lead_id', $lead->id)->exists()) {
				return $this->error('This lead has already been converted to a contact');
			}

			// Create new contact
			$contactId = (string) Str::uuid();

			$contact = Contact::create([
				'id'                     => $contactId,
				'firstName'             => $lead->first_name,
				'lastName'              => $lead->last_name,
				'phoneNumber'           => $lead->phone_number,
				'email'                  => $lead->email,
				'organizationId'        => $lead->organization_id,
				'createdBy'             => $lead->created_by,
				'isConvertedFromLead' => 1,
				'convertedLeadId'      => $lead->id,
			]);

			// Update lead
			$lead->update([
				//'deleted'              => 1,
				'isConverted'         => 1,
				'convertedContactId' => $contactId,
			]);

			// Create transfer audit log
			$auditService = new AuditLogService();
			$auditService->logTransfer(
				'Lead',
				$lead->id,
				'Contact',
				$contactId,
				[
					'lead_name' => trim(($lead->first_name ?? '') . ' ' . ($lead->last_name ?? '')),
					'contact_name' => trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')),
				],
				$organizationId,
				auth()->user()->id ?? null
			);

			return $this->success([
				'contact' => $contact->transformToApiFormat()
			], 'Lead converted successfully');
		});
	}
}
