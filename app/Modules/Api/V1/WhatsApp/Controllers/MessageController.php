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
use App\Modules\Api\V1\WhatsApp\Models\WhatsAppMedia;
use App\Http\Controllers\ApiController;
use App\Modules\Api\V1\WhatsApp\Models\WhatsAppTemplate;
use App\Services\CRM\RecordObject;
use App\Traits\ResultTrait;
use Illuminate\Support\Str;
use Carbon\Carbon;


class MessageController extends Controller{
	use ResultTrait;

	public function send(Request $request, string $module, string $recordId){
		$values = $request->input('data.values', []);
		$orgId  = auth()->user()->organization_id;
		/** Basic validation */
		
		if (empty($values['recipients']) || empty($values['channelId']) || empty($values['type']) ) {
			return $this->error('recipients, channelId and type are required');
		}

		/** Load CRM record */
		try {
			$record = RecordObject::make($module, $recordId);
		} catch (\Throwable $e) {
			return $this->error($e->getMessage());
		}

		if (!$record) {
			return $this->error('Record not found');
		}

		/** Init service */
		$service = new WhatsAppApiService($orgId, $values['channelId']);
		if ($service->noService) {
			return $this->error('Invalid or inactive WhatsApp channel');
		}

		/** Validate WhatsApp account */
		$check = $service->validateAccount();
		if ($check['success'] === false) {
			return $this->error($check['message']);
		}

		$results = [];

		foreach ($values['recipients'] as $fieldName) {

			$crmField = DB::table('crm_fields')
              		->where('modulename', $module)
                    	->where('apifieldname', $fieldName)
		    	->first('fieldname');
		
			if(!$crmField){
				$results[] = [
                                        'crm_field' => $fieldName,
                                        'success'   => false,
                                        'error'     => "Field Name {$fieldName} not found"
                                ];
                                continue;
			}
			$fieldName = $crmField->fieldname;
			
			$to = $record->{$fieldName} ?? null;
			$msgText = '';
			if (!$to) {
				$results[] = [
					'crm_field' => $fieldName,
					'phone'     => $to,
					'success'   => false,
					'error'     => 'Recipient not found'
				];
				continue;
			}

			/** Route by message type */
			switch ($values['type']) {
				/** 1️⃣ TEXT MESSAGE */
			case 'message':
				$msgText = $values['details']['text'];
				$response = $service->sendTextMessage($to,$msgText,$this->buildLog($orgId, $values, $module, $fieldName, $to, 'text', $recordId,['message'=>$msgText]));
				break;

				/** 2️⃣ TEMPLATE MESSAGE */
			case 'template':
				$templateId = $values['details']['templateId'] ?? null;
				$language = $values['details']['language'] ?? 'en_US';
				if (!$templateId) {
					$results[] = [
						'crm_field' => $fieldName,
						'phone'     => $to,
						'success'   => false,
						'error'     => 'templateId missing'
					];
					continue 2;
				}
				$check = $service->validateTemplateMappings($orgId,$templateId,$module);
				if ($check['success'] === false) {
					$results[] = [
						'crm_field'         => $fieldName,
						'phone'                => $to,
						'success'           => false,
						'error'             => $check['message'],
						'missing_variables' => $check['missing_variables'] ?? []
					];
					continue 2;
				}
				$build = $service->buildTemplateComponents($orgId,$templateId,$module,$record);
				if ($build['status'] === false) {
					$results[] = [
						'crm_field' => $fieldName,
						'phone'      => $to,
						'success' => false,
						'error'   => $build['message']
					];
					continue 2;
				}
				$template = WhatsAppTemplate::find($templateId);
				$renderedMessage = $service->renderTemplateMessageForLog(
					$template->components,      // MASTER TEMPLATE
					$build['components']        // SEND COMPONENTS
				);
				$msgText = $renderedMessage;
				$response = $service->sendTemplateMessage(
					$to,
					$build['template_name'],
					$language,
					$build['components'],
					$this->buildLog($orgId, $values, $module, $fieldName, $to, 'template', $recordId,
					[
						// ✅ save readable message
						'message' => $renderedMessage,
						// optional: still store raw components in info
						'info'    => ['components' => $build['components'] ]
					])
				);
				break;

				/** 3️⃣ INTERACTIVE MESSAGE */
			case 'interactive':
				$name = $values['details']['name'] ?? null;

				$interactive = WhatsAppInteractive::with('items')
					->where('organization_id', $orgId)
					->where('whatsapp_channel_id', $values['channelId'])
					->where('name', $name)
					->where('is_active', 1)
					->first();

				if (!$interactive) {
					$results[] = [
						'crm_field' => $fieldName,
						'phone'      => $to,
						'success' => false,
						'error'   => 'Interactive not found'
					];
					continue 2;
				}

				$payload  = $service->buildInteractivePayload($interactive, $interactive->items);
				$msgText  = $service->renderInteractiveMessageForLog($interactive, $interactive->items);
				
				// Build log with rendered message and full interactive info
				$logData = $this->buildLog($orgId, $values, $module, $fieldName, $to, 'interactive', $recordId, [
					'message' => $msgText,
					'info'    => [
						'interactive_id' => $interactive->id,
						'interactive_name' => $interactive->name,
						'items' => $interactive->items->toArray()
					]
				]);

				$log      = $service->createMessageLog($logData);
				$response = $service->sendRawMessage($to, $payload);
				$service->updateMessageLog($log, $response);
				$response['log'] = $log;
				break;

				/** 4️⃣ MEDIA MESSAGE */
			case 'media':

				$details = $values['details'] ?? [];

				if (empty($details['media_type'])) {
					$results[] = [
						'crm_field' => $fieldName,
						'phone'      => $to,
						'success' => false,
						'error'   => 'media_type is required'
					];
					continue 2;
				}
				// * 1️⃣ If media_id already exists (reuse uploaded media)
				if (!empty($details['media_id'])) {

					$msgText = $details['caption'] ?? ('Media: ' . $details['media_type']);
					$response = $service->sendMedia_Message($to,$details['media_type'],$details['media_record_id'] ?? null,$details['media_id'],$details['caption'] ?? null,$this->buildLog($orgId, $values, $module, $fieldName, $to, 'media', $recordId));
					break;
				}
				 //* 2️⃣ Upload media first (file upload flow)
				if (!$request->hasFile('file')) {
					$results[] = [
						'crm_field' => $fieldName,
						'phone'      => $to,
						'success' => false,
						'error'   => 'file or media_id required'
					];
					continue 2;
				}

				// Upload once
				$upload = $service->uploadMediaToWhatsApp(
					$request->file('file'),
					$values['channelId']
				);

				if (($upload['success'] ?? false) !== true) {
					$results[] = [
						'crm_field' => $fieldName,
						'phone'      => $to,
						'success' => false,
						'error'   => $upload['message'] ?? 'Media upload failed'
					];
					continue 2;
				}

				$mediaId     = $upload['media_id']; // WhatsApp media id
				$mediaRowId = $upload['id'];       // DB media log id
				$msgText     = $details['caption'] ?? ('Media: ' . $details['media_type']);

				// Send media
				$response = $service->sendMedia_Message(
					$to,
					$details['media_type'],
					$mediaRowId,
					$mediaId,
					$details['caption'] ?? null,
					array_merge(
						$this->buildLog($orgId, $values, $module, $fieldName, $to, 'media', $recordId),
						['media_id' => $mediaRowId]
					)
				);
				break;
			default:
				$results[] = [
					'crm_field' => $fieldName,
					'phone'      => $to,
					'success' => false,
					'error'   => 'Invalid message type'
				];
				continue 2;
			}
			if ($response['success'] ?? false) {
				$msgData = $this->formatMessageLog($response['log']);
				$msgData['success'] = true;

				// Attach media details if it's a media message
				if ($msgData['type'] === 'media' && !empty($msgData['media_id'])) {
					$media = WhatsAppMedia::find($msgData['media_id']);
					if ($media) {
						$msgData['media_details'] = [
							'file_name'  => $media->file_name,
							'local_path' => $media->local_path,
							'mime_type'  => $media->mime_type,
							'url'        => '/whatsapp_media/' . $media->local_path
						];
					}
				}

				$results[] = $msgData;
			} else {
				$results[] = [
					'crm_field' => $fieldName,
					'phone'      => $to,
					'success' => false,
					'error'   => $response['message'] ?? 'Failed'
				];
			}
		}
		$total = count($results);
		$successCount = 0;
		foreach ($results as $res) {
			if ($res['success'] ?? false) {
				$successCount++;
			}
		}

		if ($successCount === 0 && $total > 0) {
			$errors = [];
			foreach ($results as $res) {
				$errors[] = ($res['phone'] ?? $res['crm_field'] ?? 'Unknown') . ': ' . ($res['error'] ?? 'Unknown error');
			}
			return $this->error('Failed to send all WhatsApp messages: ' . implode(', ', $errors));
		}

		if ($successCount < $total) {
			return $this->success(['values' => $results], 'WhatsApp messages partially sent');
		}

		return $this->success(['values' => $results], 'WhatsApp messages processed');
	}

	private function buildLog(
		string $orgId,
		array $values,
		string $module,
		string $field,
		string $to,
		string $type,
		string $recordId,
		array $extra = []
	): array {
		return array_merge([
			'organization_id'     => $orgId,
			'whatsapp_channel_id' => $values['channelId'],
			'type'                => $type,
			'crm_module'          => $module,
			'crm_field'           => $field,
			'crm_field_value'     => $to,
			'related_module'      => $module,
			'related_id'          => $recordId,
			'direction'           => 'outgoing',
		], $extra);
	}	
	public function fetchAllChats(string $module, string $recordId)
	{
		$orgId = auth()->user()->organization_id;

		try {
			$record = RecordObject::make($module, $recordId);
		} catch (\Throwable $e) {
			return $this->error($e->getMessage());
		}

		if (!$record) {
			return $this->error('Record not found');
		}

		$messages = WhatsAppMessage::where('organization_id', $orgId)
			->where('related_module', $module)
			->where('related_id', $recordId)
			->where('deleted', 0)
			->orderBy('created_at', 'asc')
			->get();
		/** 1. Pre-fetch media details to avoid N+1 */
		$mediaIds = $messages->where('type', 'media')->pluck('media_id')->filter()->unique();
		$mediaMap = [];
		if ($mediaIds->isNotEmpty()) {
			$mediaMap = WhatsAppMedia::where('organization_id', $orgId)
				->whereIn('id', $mediaIds)
				->get()
				->keyBy('id');
		}
		

		$service = null; 

		$formattedMessages = $messages->map(function ($msg) use ($orgId, $mediaMap, &$service) {
			$messageText = $msg->message;

			/** 2. Attempt to reconstruct interactive message if it looks like legacy/body-only */
			if ($msg->type === 'interactive' && $msg->direction === 'outgoing') {
				// If message doesn't contain "[Button]" or is very short (body only)
				if (!str_contains($messageText, '[Button]') && !str_contains($messageText, '---')) {
					
					if (!$service) {
						$service = new \App\Services\WhatsApp\WhatsAppApiService($orgId);
					}

					// Try to find the interactive definition by body text
					$interactive = \App\Modules\Api\V1\WhatsApp\Models\WhatsAppInteractive::with('items')
						->where('organization_id', $orgId)
						->where('body', $messageText)
						->first();
					
					if ($interactive) {
						$messageText = $service->renderInteractiveMessageForLog($interactive, $interactive->items);
					}
				}
			}

			$data = $this->formatMessageLog($msg);
			$data['message'] = $messageText;

			/** 3. Attach media details if available */
			if ($msg->type === 'media' && !empty($msg->media_id)) {
				$media = $mediaMap->get($msg->media_id);
				if ($media) {
					$data['media_details'] = [
						'file_name'  => $media->file_name,
						'local_path' => $media->local_path,
						'mime_type'  => $media->mime_type,
						'url'        => '/whatsapp_media/' . $media->local_path
					];
				}
			}

			return $data;
		});

		return $this->success([
			'module'    => $module,
			'record_id' => $recordId,
			'messages'  => $formattedMessages,
		]);
	}

	private function formatMessageLog(WhatsAppMessage $msg): array {
		return [
			'id'          => $msg->id,
			'direction'   => $msg->direction,
			'type'        => $msg->type,
			'message'     => $msg->message,
			'media_id'    => $msg->media_id,
			'crm_field'   => $msg->crm_field,
			'phone'       => $msg->crm_field_value,
			'status'      => $msg->status,
			'created_at'  => $msg->created_at->toDateTimeString(),
		];
	}
}
