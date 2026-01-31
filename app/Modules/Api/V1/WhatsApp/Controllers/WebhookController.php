<?php

namespace App\Modules\Api\V1\WhatsApp\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Modules\Api\V1\WhatsApp\Models\WhatsAppConversation;
use App\Modules\Api\V1\WhatsApp\Models\WhatsAppChannel;
use App\Modules\Api\V1\WhatsApp\Models\WhatsAppMessage;
use App\Modules\Api\V1\WhatsApp\Models\WhatsAppTemplateFieldMapping;
use Illuminate\Support\Facades\Log;
use App\Services\WhatsApp\WhatsAppApiService;
use App\Traits\ResultTrait;
use Illuminate\Support\Str;
use Carbon\Carbon;

class WebhookController extends Controller{
	use ResultTrait;
	public function verify(Request $request)
	{
		$verifyToken = config('services.whatsapp.verify_token');

		if ($request->get('hub_verify_token')  && $request->get('hub_verify_token') ===  $verifyToken ) {

			return response($request->get('hub_challenge'), 200);
		}
		return $this->error('Invalid verify token');
	}
	public function handle(Request $request)
	{
		$raw = $request->getContent();

		$payload = json_decode($raw, true);

		Log::channel('daily')->info('WhatsApp POST RAW', [
			'raw' => $payload['entry'][0]['changes'],
		]);
		if (empty($payload)) {
			return $this->success(null, 'Empty payload');
		}

		// WhatsApp structure: entry[] -> changes[] -> value
		if (!empty($payload['entry'])) {
			foreach ($payload['entry'] as $entry) {
				$wabaId = $entry['id'] ?? null;
				if (!empty($entry['changes'])) {
					foreach ($entry['changes'] as $change) {
						$this->handleChangeValue($change['value'] ?? [], $wabaId);
					}
				}
			}
		}

		return $this->success(null, 'Webhook processed');
	}
	private function handleChangeValue(array $value, ?string $wabaId = null)
	{
		if (empty($value)) {
			return;
		}
		// 1. Messages
		if (!empty($value['messages'])) {
		 Log::channel('daily')->info('WhatsApp handleChangeValue', [
                        'raw' => $value['metadata']['phone_number_id'],
                ]);
		 	$this->processMessages($value, $wabaId);
			return;
		}

		// 2. Statuses
		if (!empty($value['statuses'])) {
			$this->processStatuses($value, $wabaId);
			return;
		}

		// 3. Contacts / Opt-outs (if any special handling needed)
		// logic for opt-outs can go here if needed
	}
	private function processMessages(array $value, ?string $wabaId)
	{
		Log::channel('daily')->info('WhatsApp Incoming Messages', ['value' => $value]);
		$phoneNumberId = $value['metadata']['phone_number_id'] ?? null;
		if (!$phoneNumberId) {
			Log::channel('daily')->warning('WhatsApp Webhook: Missing phone_number_id');
			return;
		}

		$query = WhatsAppChannel::where('phone_number_id', $phoneNumberId)
			->where('is_active', 1);

		if ($wabaId) {
			$query->where('business_id', $wabaId);
		}

		$channel = $query->first();
		$service = new WhatsAppApiService($channel->organization_id, $channel->id);

		if (!$channel) {
			Log::channel('daily')->warning("WhatsApp Webhook: Channel not found or inactive for ID {$phoneNumberId} (WABA: {$wabaId})");
			return;
		}

		foreach ($value['messages'] as $msg) {

			$from = $msg['from'] ?? null;
			$type = $msg['type'] ?? 'text';
			$content = null;
			$mediaId = null;
			$relatedModule = null;
			$relatedId     = null;
			$crmField      = null;
			if ($type === 'text') {
				$content = $msg['text']['body'] ?? null;
			} elseif (in_array($type, ['image', 'video', 'audio', 'document', 'sticker'])) {
				// For media types, we store the type as content or caption if available
				$content = $msg[$type]['caption'] ?? $type;
				$mediaId = $msg[$type]['id'] ?? null;
			} elseif ($type === 'location') {
				$loc = $msg['location'] ?? [];
				$content = $loc['name'] ?? $loc['address'] ?? ($loc['latitude'] . ',' . $loc['longitude']);
			} elseif ($type === 'button') {
				$content = $msg['button']['text'] ?? 'button_response';
			} elseif ($type === 'interactive') {
				// simplify interactive responses
				$interactive = $msg['interactive'] ?? [];
				$content = $interactive['list_reply']['title']
					?? $interactive['button_reply']['title']
					?? $interactive['nfm_reply']['response_json'] // Flows
					?? 'interactive_response';

			}
			// 🔍 Strategy: Determine Target(s) for this message
			$targets = [];

			$contextId = $msg['context']['id'] ?? null;
			$parent = null;
			Log::channel('daily')->warning("WhatsApp Webhook: Context " . $contextId );
			Log::channel('daily')->warning("WhatsApp Webhook: Content " . $content );
			// 1. Try to find parent context
			if ($contextId) {
				$parent = WhatsAppMessage::where('message_id', $contextId)
					->where('whatsapp_channel_id', $channel->id)
					->first();
			}

			if ($parent && !empty($parent->related_module) && !empty($parent->related_id)) {
				// Case A: Reply to specific message -> Link to SAME record only
				$targets[] = [
					'related_module' => $parent->related_module,
					'related_id'     => $parent->related_id,
					'crm_field'      => $parent->crm_field,
				];
			} else {
				// Case B: No context or new thread -> Find by phone number
				$foundRecords = $service->findRecordByPhoneNumber($from);
				if (!empty($foundRecords)) {
					$targets = $foundRecords;
				} else {
					// Case C: No context and no record found -> Unlinked message
					$targets[] = [
						'related_module' => null,
						'related_id'     => null,
						'crm_field'      => null,
					];
				}
			}
			Log::channel('daily')->info("WhatsApp Log Created for " . json_encode($targets));
			Log::channel('daily')->warning("WhatsApp Webhook: Targets found -> " . count($targets));

			// 🚀 Create Log for EACH target
			foreach ($targets as $target) {
				try {
					$service->createMessageLog([
						'organization_id' => $channel->organization_id,
						'whatsapp_channel_id' => $channel->id,
						'direction' => 'incoming',
						'message_id' => $msg['id'] ?? null,
						'from_number' => $from,
						'to_number' => $value['metadata']['display_phone_number'] ?? null,
						'type' => $type,
						'message' => $content,
						'media_id' => $mediaId,
						'status' => 'received',
						'info' => $msg,
						'related_module' => $target['related_module'],
						'related_id'     => $target['related_id'],
						'crm_field'      => $target['crm_field'],
						'crm_field_value' => $from,
					]);
					
					Log::channel('daily')->info("WhatsApp Log Created for {$target['related_module']} #{$target['related_id']}");

				} catch (\Exception $e) {
					Log::channel('daily')->error('WhatsApp Message Create Error', ['error' => $e->getMessage(), 'msg' => $msg]);
				}
			}
		}
	}

	private function processStatuses(array $value, ?string $wabaId)
	{
		Log::channel('daily')->info('WhatsApp Status Update', ['value' => $value]);

		$phoneNumberId = $value['metadata']['phone_number_id'] ?? null;
		if (!$phoneNumberId) {
			return; // Silent fail or log warning
		}

		$query = WhatsAppChannel::where('phone_number_id', $phoneNumberId);

		if ($wabaId) {
			$query->where('business_id', $wabaId);
		}

		$channel = $query->first();

		if (!$channel) {
			return;
		}

		Log::channel('daily')->info('WhatsApp Status Update', ['channel id' => $channel->id]);
		foreach ($value['statuses'] as $status) {
			$wamid = $status['id'] ?? null;
			$newStatus = $status['status'] ?? null; // sent, delivered, read, failed
			if ($wamid && $newStatus) {
				WhatsAppMessage::where('message_id', $wamid)
					->where('whatsapp_channel_id', $channel->id)
					->update([
						'status' => $newStatus,
						'updated_at' => now(),
						'info' => json_encode($status, JSON_UNESCAPED_UNICODE),
					]);
			}

		}
	}


	public function optOut(Request $request)
	{
		return response()->json(['opted_out' => true]);
	}
    //
}
