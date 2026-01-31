<?php

namespace App\Modules\Api\V1\WhatsApp\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Services\WhatsApp\WhatsAppApiService;
use App\Modules\Api\V1\WhatsApp\Models\WhatsAppChannel;
use App\Modules\Api\V1\WhatsApp\Models\WhatsAppTemplateFieldMapping;
use App\Modules\Api\V1\WhatsApp\Models\WhatsAppMessage;
use App\Modules\Api\V1\WhatsApp\Models\WhatsAppInteractive;
use App\Modules\Api\V1\WhatsApp\Models\WhatsAppInteractiveItem;
use App\Http\Controllers\ApiController;
use App\Services\CRM\RecordObject;
use App\Traits\ResultTrait;
use Illuminate\Support\Str;
use Carbon\Carbon;

class InteractiveController extends Controller
{
	use ResultTrait;

	public function list()
	{
		$orgId = auth()->user()->organization_id;

		$data = WhatsAppInteractive::where('organization_id', $orgId)
			->where('is_active', 1)
			->get();

		return $this->success(['values' => $data]);
	}

	public function save(Request $request)
	{
		$values = $request->input('data.values', []);
		$orgId = auth()->user()->organization_id;

		if (empty($values['whatsapp_channel_id'])) {
			return $this->error('Channel required');
		}

		// validate channel belongs to org
		$channel = WhatsAppChannel::where('id', $values['whatsapp_channel_id'])
			->where('organization_id', $orgId)
			->first();

		if (!$channel) {
			return $this->error('Invalid WhatsApp channel');
		}

		$service = new WhatsAppApiService($orgId, $values['whatsapp_channel_id']);

		$result = $service->saveInteractive($values, $orgId, auth()->id());

		if (($result['success'] ?? false) !== true) {
			return $this->error($result['message'] ?? 'Save failed');
		}

		return $this->success([
			'id' => $result['id']
		], 'Interactive saved successfully');
	}
	public function sendInteractive(Request $request, string $module, string $recordId)
	{
		$values = $request->input('data.values', []);
		$orgId  = auth()->user()->organization_id;

		if (empty($values['name']) || empty($values['to']) || empty($values['whatsapp_channel_id'])) {
			return $this->error('name, to and whatsapp_channel_id are required');
		}
		try {
			$record = RecordObject::make($module, $recordId);
		} catch (\Throwable $e) {
			return $this->error($e->getMessage());
		}

		if (!$record) {
			return $this->error('Record not found');
		}
		$service = new WhatsAppApiService($orgId, $values['whatsapp_channel_id']);
		if (!empty($service->noService)) {
			return $this->error('Invalid or inactive WhatsApp channel');
		}
		// 1) Validate account before send
		$check = $service->validateAccount();
		if ($check['success'] === false) {
			return $this->error($check['message']);
		}

		// 2) Get interactive by name
		$interactive = WhatsAppInteractive::with('items')
			->where('organization_id', $orgId)
			->where('whatsapp_channel_id', $values['whatsapp_channel_id'])
			->where('name', $values['name'])
			->where('is_active', 1)
			->first();

		if (!$interactive) {
			return $this->error('Interactive not found');
		}

		$results = [];

		foreach ($values['to'] as $fieldName) {
			$to = $record->{$fieldName} ?? null;

			if (!$to) {
				$results[] = [
					'field' => $fieldName,
					'success' => false,
					'error' => 'Phone number not found'
				];
				continue;
			}

			// 3) Build payload using service
			$payload = $service->buildInteractivePayload(
				$interactive,
				$interactive->items
			);

			// 4) Create dummy log
			$log = $service->createMessageLog([
				'organization_id' => $orgId,
				'whatsapp_channel_id' => $values['whatsapp_channel_id'],
				'type' => 'interactive',
				'crm_module' => $module,
				'crm_field' => $fieldName,
				'crm_field_value' => $to,
				'message' => $interactive->body,
				'direction' => 'outgoing'
			]);

			// 5) Send
			$response = $service->sendRawMessage($to, $payload);

			// 6) Update log
			$service->updateMessageLog($log, $response);

			$results[] = [
				'field' => $fieldName,
				'to' => $to,
				'success' => $response['success'] ?? false,
				'response' => $response['response'] ?? null,
				'error' => $response['success'] ? null : ($response['message'] ?? 'Send failed')
			];
		}
		return $this->success(['values' => $results], 'Interactive messages processed');
	}
}
