<?php

namespace App\Services\WhatsApp;
use App\Modules\Api\V1\WhatsApp\Models\WhatsAppChannel;
use App\Modules\Api\V1\WhatsApp\Models\WhatsAppTemplateFieldMapping;
use App\Modules\Api\V1\WhatsApp\Models\WhatsAppMessage;
use App\Modules\Api\V1\WhatsApp\Models\WhatsAppTemplate;
use App\Modules\Api\V1\WhatsApp\Models\WhatsAppMedia;
use App\Modules\Api\V1\WhatsApp\Models\WhatsAppChannelTemplateRel;
use App\Modules\Api\V1\WhatsApp\Models\WhatsAppInteractive;
use App\Modules\Api\V1\WhatsApp\Models\WhatsAppInteractiveItem;
use App\Http\Controllers\ApiController;
use App\Traits\ResultTrait;
use Illuminate\Support\Facades\Log;
use App\Services\CRM\RecordObject;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Exception;


class WhatsAppApiService{
	        use ResultTrait;
	protected string $accessToken;
	protected string $phoneNumberId;
	public ?string $channelId = null;
	public string $organizationId;
	public bool $noService = false;
	public string $businessId = '';
	protected string $baseUrl = 'https://graph.facebook.com/v22.0';
	
	public function __construct(string $organizationId, string $channelId = '')
	{
		$this->organizationId = $organizationId;
		$this->channelId = $channelId;

		$this->accessToken = '';
		$this->phoneNumberId = '';
		$this->businessId = '';
		$this->noService = false;

		// Only auto-load when channelId is provided
		if (!empty($channelId)) {
			$config = WhatsAppChannel::where('organization_id', $organizationId)
				->where('id', $channelId)
				->where('is_active', 1)
				->first();

			if (!$config) {
				$this->noService = true;
				return;
			}

			$this->accessToken   = $config->access_token;
			$this->phoneNumberId = $config->phone_number_id;
			$this->businessId    = $config->business_id;
		}
	}
	public function list()
	{
		$orgId = auth()->user()->organization_id;
		$channels = WhatsAppChannel::where('organization_id', $orgId)
			->where('is_active', 1)
			->get();
		return $this->success([
			'values' => $channels
		]);
	}
	public function validateAccount(array $data = []): array
	{
		$businessId = $this->businessId ?: ($data['business_id'] ?? '');
		$accessToken = $this->accessToken ?: ($data['access_token'] ?? '');
	
		if (empty($businessId)) {
			return [
				'success' => false,
				'message' => 'Business Id Required'
			];
		}
		$url = "{$this->baseUrl}/{$businessId}";
		$response = self::request(
			$url,
			$accessToken,
			['fields' => 'id'],
			'GET'
		);
		if (($response['success'] ?? false) !== true) {
			return [
				'success' => false,
				'message' => 'Unable to connect to WhatsApp API'
			];
		}
		if (!empty($response['response']['error'])) {
			return [
				'success' => false,
				'message' => $response['response']['error']['message'] ?? 'Invalid WhatsApp credentials'
			];
		}
		// business id must match
		if(($response['response']['id'] ?? null) !== $businessId) {
			return [
				'success' => false,
				'message' => 'Business ID mismatch'
			];
		}
		return [
			'success' => true
		];
	}
	public function saveAccount(string $orgId, array $data): array
	{
		// Basic required fields
		foreach (['app_id','app_secret','phone_number_id','business_id','access_token'] as $field) {
			if (empty($data[$field])) {
				return [
					'success' => false,
					'message' => "$field is required"
				];
			}
		}
		// Validate with WhatsApp API using input data (not constructor data)
		$check = $this->validateAccount($data);
		if ($check['success'] === false) {
			return $check;
		}
		// Save or update per organization + phone number
		$channel = WhatsAppChannel::updateOrCreate(
			[
				'organization_id' => $orgId,
				'phone_number_id' => $data['phone_number_id'],
			],
			[
				'id' => (string) \Illuminate\Support\Str::uuid(),
				'name'            => $data['name'] ?? 'WhatsApp Account',
				'desc'            => $data['description'] ?? null,
				'app_id'          => $data['app_id'],
				'app_secret'      => $data['app_secret'],
				'phone_number_id' => $data['phone_number_id'],
				'business_id'     => $data['business_id'],
				'access_token'    => $data['access_token'],
				'is_active'       => true,
				'created_by'      => auth()->id(),
				'updated_at'      => now(),
			]
		);
		return [
			'success' => true,
			'channel' => $channel
		];
	}
	public static function request(string $url,string $accessToken,array $payload = [],string $method = 'POST',array $headers = [],bool $isMultipart = false): array {
		$ch = curl_init($url);
		$defaultHeaders = [
			'Authorization: Bearer ' . $accessToken,
		];
		if (!$isMultipart) {
			$defaultHeaders[] = 'Content-Type: application/json';
		}
		$options = [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 30,
			CURLOPT_HTTPHEADER     => array_merge($defaultHeaders, $headers),
		];
		switch (strtoupper($method)) {
		case 'POST':
			$options[CURLOPT_POST] = true;
			$options[CURLOPT_POSTFIELDS] = $isMultipart ? $payload : json_encode($payload);
			break;

		case 'PUT':
		case 'PATCH':
			$options[CURLOPT_CUSTOMREQUEST] = $method;
			$options[CURLOPT_POSTFIELDS] = $isMultipart ? $payload : json_encode($payload);
			break;
		case 'GET':
			if (!empty($payload)) {
				$url .= '?' . http_build_query($payload);
				curl_setopt($ch, CURLOPT_URL, $url);
			}
			break;
		case 'DELETE':
			$options[CURLOPT_CUSTOMREQUEST] = 'DELETE';
			break;
		}
		curl_setopt_array($ch, $options);
		$response = curl_exec($ch);
		$error    = curl_error($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		if ($error) {
			return [
				'success' => false,
				'message' => $error,
			];
		}
		$decodedString = $response;
		$decoded = json_decode($decodedString, true);
		$errorMsg = '';
		$success = ($httpCode >= 200 && $httpCode < 300);

		if (!empty($decoded['error'])) {
			$success = false;
			$errorMsg = $decoded['error']['message'] ?? 'Unknown WhatsApp API error';
		}

		if (!$success && empty($errorMsg)) {
			$errorMsg = "HTTP request failed with status code: {$httpCode}";
		}

		return [
			'httpCode' => $httpCode,
			'success'  => $success,
			'message'  => $errorMsg,
			'response' => $decoded,
		];
	}
	public function extractTemplateVariables(array $template): array
	{
		$variables = [];
		foreach ($template['components'] as $component) {

			// 1) BODY
			if ($component['type'] === 'BODY') {

				// No params
				if (!isset($component['example'])) {
					continue;
				}

				// POSITIONAL
				if (isset($component['example']['body_text'])) {
					foreach ($component['example']['body_text'][0] as $index => $val) {
						$variables[] = [
							'template_variable' => (string)($index + 1),
							'component_type' => 'BODY',
							'button_index' => null,
						];
					}
				}

				// NAMED
				if (isset($component['example']['body_text_named_params'])) {
					foreach ($component['example']['body_text_named_params'] as $param) {
						$variables[] = [
							'template_variable' => $param['param_name'],
							'component_type' => 'BODY',
							'button_index' => null,
						];
					}
				}
			}

			// 2) BUTTONS (only if URL type with params)
			if ($component['type'] === 'BUTTONS') {
				foreach ($component['buttons'] as $btnIndex => $btn) {
					if ($btn['type'] === 'URL' && str_contains($btn['url'], '{{')) {

						preg_match_all('/\{\{(.*?)\}\}/', $btn['url'], $matches);

						foreach ($matches[1] as $var) {
							$variables[] = [
								'template_variable' => $var,
								'component_type' => 'BUTTON',
								'button_index' => $btnIndex,
							];
						}
					}
				}
			}

			// 3) HEADER with TEXT
			if ($component['type'] === 'HEADER' && isset($component['text'])) {
				if (str_contains($component['text'], '{{')) {
					preg_match_all('/\{\{(.*?)\}\}/', $component['text'], $matches);

					foreach ($matches[1] as $var) {
						$variables[] = [
							'template_variable' => $var,
							'component_type' => 'HEADER',
							'button_index' => null,
						];
					}
				}
			}
		}

		return $variables;
	}
	public function getTemplateMapping(string $templateId): array
	{
		$template = WhatsAppTemplate::where('id', $templateId)
			->where('organization_id', $this->organizationId)
			->where('whatsapp_channel_id', $this->channelId)
			->first();

		if (!$template) {
			throw new \Exception('Template not found');
		}

		$mappings = WhatsAppTemplateFieldMapping::where(
			'template_id',
			$templateId
		)
			->orderBy('component_type')
			->get();

		return [
			'values' => [
				'module'      => $template->module ?? '',
				'template_id' => $template->id,
				'language'    => $template->language,
				'mappings'    => $mappings->map(function ($m) {
					return [
						'template_variable' => $m->template_variable,
						'crm_module'        => $m->crm_module ?? '',
						'crm_field'         => $m->crm_field ?? '',
						'component_type'    => strtolower($m->component_type),
					];
				})->values()
			]
		];
	}
	public function updateTemplateMapping(
		string $templateId,
		array $data
	): array {
		if (empty($data['mappings']) || empty($data['template_id'])) {
			throw new \Exception('Invalid mapping payload');
		}

		$template = WhatsAppTemplate::where('id', $templateId)
			->where('organization_id', $this->organizationId)
			->where('whatsapp_channel_id', $this->channelId)
			->first();

		if (!$template) {
			throw new \Exception('Template not found');
		}

		 $template->update([
  		      'module' => $data['module']
   	 	]);
	
		foreach ($data['mappings'] as $map) {

			if (
				empty($map['template_variable']) ||
				empty($map['crm_module']) ||
				empty($map['crm_field'])
			) {
				continue; // skip invalid rows
			}

			WhatsAppTemplateFieldMapping::where(
				'organization_id',
				$this->organizationId
			)
				->where('template_id', $templateId)
				->where('template_variable', $map['template_variable'])
				->update([
					'crm_module'     => $map['crm_module'],
					'crm_field'      => $map['crm_field'],
					'component_type' => strtoupper($map['component_type'] ?? 'BODY'),
				]);
		}
		return [
			'message' => 'Template mapping updated successfully'
		];
	}
	public function syncSingleTemplate(string $templateUuid): array
	{
		/** Step 1: Get local template */
		$template = WhatsAppTemplate::where('id', $templateUuid)
			->where('organization_id', $this->organizationId)
			->where('whatsapp_channel_id', $this->channelId)
			->first();

		if (!$template) {
			throw new \Exception('Template not found');
		}

		/** Step 2: Fetch template from WhatsApp */
		$url = "{$this->baseUrl}/{$this->businessId}/message_templates";
		$response = self::request(
			$url,
			$this->accessToken,
			[],
			'GET'
		);
		$response = self::request(
                        $url,
                        $this->accessToken,
                        [
                                'name' => $template->template_name
                        ],
                        'GET'
                );

		if (!empty($response['response']['error'] ?? null)) {
			throw new \Exception(
				$response['response']['error']['message'] ?? 'Failed to sync template'
			);
		}
		$tplResponse = $response['response'];
		if (empty($tplResponse['data']) || !isset($tplResponse['data'][0])) {
			throw new \Exception('Template not found in WhatsApp response');
		}
		$tpl = $tplResponse['data'][0];

		/** Step 3: Update whatsapp_templates */
		$template->update([
			'template_name' => $tpl['name'],
			'language'      => $tpl['language'],
			'format'        => $tpl['parameter_format'],
			'status'        => $tpl['status'],
			'components'    => $tpl['components'] ?? [],
			'category'      => $tpl['category'] ?? null,
		]);

		/** Step 4: Rebuild mappings */
		return $this->rebuildTemplateMappings($template);
	}
	
	public function rebuildTemplateMappings(WhatsAppTemplate $template): array
	{
		/** Step 1: Delete old mappings */
		WhatsAppTemplateFieldMapping::where(
			'template_id',
			$template->id
		)->delete();

		/** Step 2: Extract variables */
		$vars = $this->extractTemplateVariables([
			'parameter_format' => $template->format,   // POSITIONAL | NAMED
			'components'       => $template->components ?? [],
		]);

		$mappingResponse = [];

		/** Step 3: Create new mappings */
		foreach ($vars as $v) {
			WhatsAppTemplateFieldMapping::create([
				'id'                => (string) Str::uuid(),
				'organization_id'   => $this->organizationId,
				'template_id'       => $template->id,
				'template_language' => $template->language,
				'template_variable' => $v['template_variable'],
				'component_type'    => strtoupper($v['component_type']),
				'button_index'      => $v['button_index'] ?? null,
				'crm_module'        => null,
				'crm_field'         => null,
			]);

			$mappingResponse[] = [
				'template_variable' => $v['template_variable'],
				'crm_module'        => '',
				'crm_field'         => '',
				'component_type'    => strtolower($v['component_type']),
			];
		}

		/** Step 4: Return frontend-ready payload */
		return [
			'values' => [
				'module'      => '',
				'template_id' => $template->id,
				'language'    => $template->language,
				'mappings'    => $mappingResponse
			]
		];
	}

	public function saveMapping(string $orgId,string $channelId,string $templateId,array $data = []) {
		// Validate template existence
		$template = WhatsAppTemplate::where('id', $templateId)
			->where('organization_id', $orgId)
			->where('whatsapp_channel_id', $channelId)
			->first();

		if (!$template) {
			return [
				'status' => false,
				'message' => 'Invalid or missing template for this organization/channel'
			];
		}
		// Step 1: First call – create empty mappings
		if (empty($data)) {
			if (empty($template->components)) {
				return [
					'status' => false,
					'message' => 'Template has no components'
				];
			}
			$templateArr = [
				'parameter_format' => $template->format,
				'components'       => $template->components,
			];
			$vars = $this->extractTemplateVariables($templateArr);
			if (empty($vars)) {
				return [
					'status' => false,
					'message' => 'No mappable variables found in template'
				];
			}
			$mappings = [];

			foreach ($vars as $v) {
				WhatsAppTemplateFieldMapping::create([
					'id'                => (string) Str::uuid(),
					'organization_id'   => $orgId,
					'template_id'       => $templateId,
					'template_language' => $template->language,
					'template_variable' => $v['template_variable'],
					'component_type'    => $v['component_type'],
					'button_index'      => $v['button_index'],
					'crm_module'        => null,
					'crm_field'         => null,
				]);

				$mappings[] = [
					'template_variable' => $v['template_variable'],
					'crm_module'        => '',
					'crm_field'         => '',
					'component_type'    => strtolower($v['component_type']),
				];
			}

			return [
				'status' => true,
				'type'   => 'init',
				'data'   => [
					'module'      => '',
					'template_id' => $templateId,
					'language'    => $template->language,
					'mappings'    => $mappings
				]
			];
		}

		// Step 2: Update from frontend
		if (empty($data['template_id']) || empty($data['mappings']) || !is_array($data['mappings'])) {
			return [
				'status' => false,
				'message' => 'Invalid mapping payload'
			];
		}

		foreach ($data['mappings'] as $map) {
			if (empty($map['template_variable'])) {
				continue;
			}

			WhatsAppTemplateFieldMapping::where('organization_id', $orgId)
				->where('template_id', $templateId)
				->where('template_variable', $map['template_variable'])
				->update([
					'module'         => $data['module'] ?? null,
					'crm_module'     => $map['crm_module'] ?? null,
					'crm_field'      => $map['crm_field'] ?? null,
					'component_type' => strtoupper($map['component_type'] ?? 'BODY'),
				]);
		}
		return [
			'status'  => true,
			'type'    => 'update',
			'message' => 'Template mapping updated successfully'
		];
	}
	public function buildTemplateComponents(string $organizationId,string $templateId,string $module,$record ): array {
		$template = WhatsAppTemplate::where('id', $templateId)
			->where('organization_id', $organizationId)
			->first();
		if (!$template){
			return [
				'status' => false,
				'message' => 'WhatsApp template not found',
				'components' => []
			];
		}

		$templateComponents = $template->components ?? [];

		if (!$this->templateHasVariables($templateComponents)) {
			return [
				'status' => true,
				'message' => 'Template has no variables',
				'template_name' => $template->template_name,
				'components' => [] // IMPORTANT
			];
		}

		$isNamed = $template && $template->format === 'NAMED';
		$mappings = WhatsAppTemplateFieldMapping::where('organization_id', $organizationId)
			->where('template_id', $templateId)
			->where('crm_module', $module)
			->get();

		if ($mappings->isEmpty()) {
			return [
				'status' => false,
				'message' => 'Template field mappings not configured',
				'components' => []
			];
		}
		try {
			$fieldManager = \App\Models\FieldModelManager::make($module, 'EditView');
		} catch (\Throwable $e) {
			return [
				'status' => false,
				'message' => 'Invalid CRM module',
				'components' => []
			];
		}
		/** --------------------------------
		 * Build value map (template_variable => value)
		 ----------------------------------*/
		$valueMap = [];
		foreach ($mappings as $map) {
			$fieldModel = $fieldManager->getFieldModel($map->crm_field);
			if (!$fieldModel) {
				return [
					'status' => false,
					'message' => "CRM field {$map->crm_field} not found",
					'components' => []
				];
			}

			$fieldName = $fieldModel->getFieldName();
			$valueMap[$map->template_variable] = (string) ($record->{$fieldName} ?? '');
		}
		$components = [];
		/** --------------------------------
		 * Loop through template components
		 ----------------------------------*/
		foreach ($template->components as $component) {

			/* ---------- HEADER ---------- */
			if ($component['type'] === 'HEADER' && isset($component['format']) && $component['format'] === 'TEXT') {

				if (str_contains($component['text'], '{{')) {
					$components[] = [
						'type' => 'header',
						'parameters' => $this->buildParams($component, $valueMap, $isNamed)
					];
				}
			}

			/* ---------- BODY ---------- */
			if ($component['type'] === 'BODY') {
				$components[] = [
					'type' => 'body',
					'parameters' => $this->buildParams($component, $valueMap, $isNamed)
				];
			}

			/* ---------- BUTTONS ---------- */
			if ($component['type'] === 'BUTTONS') {
				foreach ($component['buttons'] as $index => $button) {

					if ($button['type'] === 'URL' && str_contains($button['url'], '{{')) {
						$components[] = [
							'type' => 'button',
							'sub_type' => 'url',
							'index' => (string) $index,
							'parameters' => $this->buildButtonParams($button, $valueMap, $isNamed)
						];
					}
				}
			}
		}
		return [
			'status' => true,
			'message' => 'Components built successfully',
			'template_name' => $template->template_name,
			'components' => $components
		];
	}
	private function templateHasVariables(array $components): bool
	{
		foreach ($components as $component) {

			// HEADER / BODY examples
			if (!empty($component['example']) && is_array($component['example'])) {
				return true;
			}

			// BUTTON examples
			if (($component['type'] ?? '') === 'BUTTONS') {
				foreach ($component['buttons'] ?? [] as $button) {
					if (!empty($button['example'])) {
						return true;
					}
				}
			}
		}

		return false;
	}
	private function buildParams(array $component, array $valueMap, bool $isNamed): array
	{
		$params = [];

		if ($isNamed) {
			foreach ($valueMap as $name => $value) {
				$params[] = [
					'type' => 'text',
					'text' => $value,
					'parameter_name' => $name
				];
			}
		} else {
			preg_match_all('/{{\d+}}/', $component['text'], $matches);
			foreach ($matches[0] as $index => $placeholder) {
				$params[] = [
					'type' => 'text',
					'text' => array_values($valueMap)[$index] ?? ''
				];
			}
		}

		return $params;
	}
	private function buildButtonParams(array $button, array $valueMap, bool $isNamed): array
	{
		if ($isNamed) {
			return [[
				'type' => 'text',
				'text' => reset($valueMap)
			]];
		}

		// POSITIONAL ({{1}})
		return [[
			'type' => 'text',
			'text' => reset($valueMap)
		]];
	}

	public function getTemplates(string $organizationId, string $channelId): array 
	{
		$query = WhatsAppTemplate::where(
			'organization_id',
			$organizationId
		);

		if (!empty($channelId)) {
			$query->where('whatsapp_channel_id', $channelId);
		}

		$templates = $query->orderBy('template_name')->get();

		$values = $templates->map(function ($tpl) {
			return [
				'id'           => $tpl->id,
				'organization_id' => $tpl->organization_id,
				'template_id'  => $tpl->template_id,   // Meta ID
				'module'	=> $tpl->module,
				'name'         => $tpl->template_name,
				'language'     => $tpl->language,
				'status'       => $tpl->status,
				'category'     => $tpl->category,
				'format'       => $tpl->format,
				'components'   => $tpl->components,
				'business_id'  => $tpl->business_id,
				'whatsapp_channel_id' => $tpl->whatsapp_channel_id,
				'created_by' => $tpl->created_by,
			];
		})->values();

		return ['success' => true,'values'=> $values];	
	}
	public function getTemplatesByModule(string $organizationId, string $channelId, ?string $module = null ): array
        {
                $query = WhatsAppTemplate::where('organization_id',$organizationId)
		 	->where('module', $module);

                if (!empty($channelId)) {
                        $query->where('whatsapp_channel_id', $channelId);
                }
                $templates = $query->orderBy('template_name')->get();
                $values = $templates->map(function ($tpl) {
                        return [
                                'id'           => $tpl->id,
                                'organization_id' => $tpl->organization_id,
                                'template_id'  => $tpl->template_id,   // Meta ID
                                'module'        => $tpl->module,
                                'name'         => $tpl->template_name,
                                'language'     => $tpl->language,
                                'status'       => $tpl->status,
                                'category'     => $tpl->category,
                                'format'       => $tpl->format,
                                'components'   => $tpl->components,
                                'business_id'  => $tpl->business_id,
                                'whatsapp_channel_id' => $tpl->whatsapp_channel_id,
                                'created_by' => $tpl->created_by,
                        ];
                })->values();

                return ['success' => true,'values'=> $values];
        }
	public function getTemplateByName(string $templateName): array
	{
		$url = "{$this->baseUrl}/{$this->businessId}/message_templates";

		$response = self::request(
			$url,
			$this->accessToken,
			[
				'name' => $templateName
			],
			'GET'
		);
		return $response;
	}
	public function getTemplateUsingName(string $templateName): array
	{
		if (empty($this->organizationId) || empty($this->channelId)) {
			return [
				'success' => false,
				'message' => 'Organization or Channel not set in service'
			];
		}

		$template = WhatsAppTemplate::where('organization_id', $this->organizationId)
			->where('whatsapp_channel_id', $this->channelId)
			->where('template_name', $templateName)
			->first();

		if (!$template) {
			return [
				'success' => false,
				'message' => 'Template not found for this organization and channel'
			];
		}
		return [
			'success' => true,
			'data' => [
				'id' => $template->id,
				'template_id' => $template->template_id,
				'name' => $template->template_name,
				'language' => $template->language,
				'format' => $template->format,
				'status' => $template->status,
				'category' => $template->category,
				'components' => $template->components,
			]
		];
	}
	public function sendTextMessage(string $to,string $message,array $logData): array {
		$log = $this->createMessageLog($logData);
		
		$response = self::request(
			"{$this->baseUrl}/{$this->phoneNumberId}/messages",
			$this->accessToken,
			[
				'messaging_product' => 'whatsapp',
				'to' => $to,
				'type' => 'text',
				'text' => ['body' => $message]
			]
		);

		$this->updateMessageLog($log, $response);
		return array_merge($response, ["log" => $log]);
	}
	public function sendInteractiveMessage(string $to, array $interactive, array $logData = [])
	{
		$payload = [
			'messaging_product' => 'whatsapp',
			'to' => $to,
			'type' => 'interactive',
			'interactive' => []
		];

		if ($interactive['type'] === 'button') {
			$payload['interactive'] = [
				'type' => 'button',
				'body' => [
					'text' => $interactive['body']
				],
				'action' => [
					'buttons' => array_map(function ($btn) {
						return [
							'type' => 'reply',
							'reply' => [
								'id' => $btn['id'],
								'title' => $btn['title']
							]
						];
					}, $interactive['buttons'])
				]
			];
		}

		if ($interactive['type'] === 'list') {
			$payload['interactive'] = [
				'type' => 'list',
				'body' => [
					'text' => $interactive['body']
				],
				'action' => [
					'button' => $interactive['button'],
					'sections' => $interactive['sections']
				]
			];
		}

		// Create message log BEFORE sending
		$log = $this->createMessageLog($logData);
		$url = "{$this->baseUrl}/{$this->phoneNumberId}/messages";

		$response = self::request($url, $this->accessToken, $payload, 'POST');

		$this->updateMessageLog($log, $response);

		return array_merge($response, ['log' => $log]);
	}
	public function sendTemplateMessage(string $to,string $templateName,string $language,array $components,array $logData ): array {
		$log = $this->createMessageLog($logData);

		$response = self::request(
			"{$this->baseUrl}/{$this->phoneNumberId}/messages",
			$this->accessToken,
			[
				'messaging_product' => 'whatsapp',
				'to' => $to,
				'type' => 'template',
				'template' => [
					'name' => $templateName,
					'language' => ['code' => $language],
					'components' => $components
				]
			]
		);

		$this->updateMessageLog($log, $response);
		return array_merge($response, ["log" => $log]);
	}
	public function updateMessageLog(WhatsAppMessage $message, array $response): void {
		$existingInfo = [];
		if (!empty($message->info)) {
			$existingInfo = is_array($message->info) ? $message->info : json_decode($message->info, true);
		}

		if ($response['success'] ?? false) {
			$newInfo = array_merge($existingInfo, [
				'status'   => 'sent',
				'response' => $response
			]);

			$message->update([
				'status'     => 'sent',
				'message_id' => $response['response']['messages'][0]['id'] ?? null,
				'info'       => json_encode($newInfo, JSON_UNESCAPED_UNICODE)
			]);
		} else {
			$newInfo = array_merge($existingInfo, [
				'status' => 'failed',
				'error'  => $response
			]);

			$message->update([
				'status' => 'failed',
				'info'   => json_encode($newInfo, JSON_UNESCAPED_UNICODE)
			]);
		}
	}
	public function createMessageLog(array $data): WhatsAppMessage{
		return WhatsAppMessage::create([
			'id' => (string) Str::uuid(),
			'organization_id' => $data['organization_id'],
			'whatsapp_channel_id' => $data['whatsapp_channel_id'],
			'direction' => $data['direction'] ?? 'outgoing',
			'type' => $data['type'],
			'message_id' => $data['message_id'] ?? null,
			'message' => isset($data['components']) ? json_encode($data['components'], JSON_UNESCAPED_UNICODE) : ($data['message'] ?? null),
			'crm_module' => $data['crm_module'] ?? null,
			'crm_field' => $data['crm_field'] ?? null,
			'crm_field_value' => $data['crm_field_value'] ?? null,
			'related_module' => $data['related_module'] ?? null,
			'related_id' => $data['related_id'] ?? null,
			'media_id' => $data['media_id'] ?? null,
			'status' => 'open',
			'info' => isset($data['info']) ? json_encode($data['info'], JSON_UNESCAPED_UNICODE) : null,
			'created_by' => auth()->id(),
			'created_at' => now(),
			'updated_at' => now(),
		]);	
	}
	public function fetchAndSyncTemplates(string $organizationId, string $channelId): array
	{
		$url = "{$this->baseUrl}/{$this->businessId}/message_templates";

		$response = self::request(
			$url,
			$this->accessToken,
			[],
			'GET'
		);

		if (!empty($response['response']['error'] ?? null)) {
			return [
				'success' => false,
				'message' => $response['response']['error'],
			];
		}

		$syncedTemplates = [];

		foreach ($response['response']['data'] ?? [] as $tpl) {

			/** 1️⃣ Sync template */
			$template = WhatsAppTemplate::updateOrCreate(
				[
					'organization_id' => $organizationId,
					'business_id'     => $this->businessId,
					'template_name'   => $tpl['name'],
					'language'        => $tpl['language'],
				],
				[
					'id'                  => (string) Str::uuid(),
					'template_id'          => $tpl['id'],
					'whatsapp_channel_id'  => $channelId,
					'module'            => null,
					'created_by'           => auth()->id(),
					'format'               => $tpl['parameter_format'],
					'status'               => $tpl['status'],
					'components'           => $tpl['components'] ?? [],
					'category'             => $tpl['category'] ?? null,
				]
			);

			WhatsAppChannelTemplateRel::updateOrCreate(
				[
					'whatsapp_channels_id' => $channelId,
					'whatsapp_template_id' => $template->id,
				],
				[
					'id' => (string) Str::uuid()
				]
			);

			/** 2️⃣ AUTO CREATE TEMPLATE FIELD MAPPINGS */
			$templateArr = [
				'parameter_format' => $template->format,
				'components'       => $template->components,
			];

			$variables = $this->extractTemplateVariables($templateArr);

			foreach ($variables as $var) {

				WhatsAppTemplateFieldMapping::firstOrCreate(
					[
						'organization_id'   => $organizationId,
						'template_id'       => $template->id,
						'template_variable' => $var['template_variable'],
						'component_type'    => $var['component_type'],
						'button_index'      => $var['button_index'],
					],
					[
						'id'                => (string) Str::uuid(),
						'template_language' => $template->language,
						'crm_module'        => null,
						'crm_field'         => null,
					]
				);
			}

			/** 3️⃣ Load mappings for response */
			$syncedTemplates[] = [
				'template_id'   => $template->id,
				'template_name' => $template->template_name,
				'language'      => $template->language,
				'status'        => $template->status,
				'category'      => $template->category,
				'mappings'      => WhatsAppTemplateFieldMapping::where(
					'template_id',
					$template->id
				)->get(),
			];
		}

		return [
			'success'   => true,
			'message'   => 'Templates synced and mappings initialized successfully',
			'templates' => $syncedTemplates
		];
	}

	public function createMediaLog(string $orgId,string $channelId,string $mediaId,$file,string $localPath) {
		return WhatsAppMedia::create([
			'id'              => (string) Str::uuid(),
			'organization_id' => $orgId,
			'whatsapp_channel_id'  => $channelId,
			'media_id'        => $mediaId,
			'mime_type'       => $file->getMimeType(),
			'file_name'       => $file->getClientOriginalName(),
			'local_path'      => $localPath,
			'created_by'      => auth()->id(),
			'created_at'      => now(),
			'updated_at'      => now(),
		]);
	}
	public function uploadMediaToWhatsApp($file, string $channelId): array
	{
		$url = "{$this->baseUrl}/{$this->phoneNumberId}/media";
		$config = WhatsAppChannel::where('organization_id', $this->organizationId)->first();
		$payload = [
			'messaging_product' => 'whatsapp',
			'type' => $file->getMimeType(),
			'file' => new \CURLFile(
				$file->getRealPath(),
				$file->getMimeType(),
				$file->getClientOriginalName()
			),
		];
		$response = self::request(
			$url,
			$this->accessToken,
			$payload,
			'POST',
			[],
			true // ✅ multipart
		);
		if ($response['success'] === false) {
			return [
				'success' => false,
				'message' => 'Media upload failed',
			];

		}
		if (empty($response['response']['id'])) {
			return [
				'success' => false,
				'message' => 'Media ID not returned'
			];
		}
		// Save media log
		$result = $this->createMediaLog($this->organizationId,$channelId,$response['response']['id'],$file, $file->store('uploads', 'whatsapp') );
		return [
			'success' => true,
			'media_id' => $response['response']['id'],
			'id'=>$result->id,
		];
	}
	public function sendMedia_Message(string $to,string $type,string $id,string $mediaId,?string $caption,array $logData ): array {

		$log = $this->createMessageLog(array_merge($logData, [
			'message' => $caption,
		]));

		$payload = [
			'messaging_product' => 'whatsapp',
			'to' => $to,
			'type' => $type,
			$type => [
				'id' => $mediaId,
			]
		];

		if ($caption && in_array($type, ['image','video','document'])) {
			$payload[$type]['caption'] = $caption;
		}

		$response = self::request(
			 "{$this->baseUrl}/{$this->phoneNumberId}/messages",
			$this->accessToken,
			$payload
		);

		$this->updateMessageLog($log, $response);

		return array_merge($response, ['log' => $log]);
	}
	public function validateTemplateMappings(string $orgId,string $templateId,string $module): array {
		// 1️⃣ Fetch template
		$template = WhatsAppTemplate::where('id', $templateId)
                        ->where('organization_id', $orgId)
			->first();
		if (!$template) {
			return [
				'success' => false,
				'message' => 'Template not found'
			];
		}
		$components = $template->components ?? [];
		// 2️⃣ Collect required variables
		$requiredVars = [];
		foreach ($components as $component) {
			/* -------- HEADER / BODY / FOOTER -------- */
			if (!empty($component['text'])) {
				preg_match_all('/{{\s*([a-zA-Z0-9_]+)\s*}}/', $component['text'], $matches);
				foreach ($matches[1] as $var) {
					$requiredVars[] = $var;
				}
			}
			/* -------- BUTTONS (URL only) -------- */
			if (($component['type'] ?? '') === 'BUTTONS') {
				foreach ($component['buttons'] ?? [] as $btn) {
					if (($btn['type'] ?? '') === 'URL' && !empty($btn['url'])) {
						preg_match_all('/{{\s*([a-zA-Z0-9_]+)\s*}}/', $btn['url'], $matches);
						foreach ($matches[1] as $var) {
							$requiredVars[] = $var;
						}
					}
				}
			}
		}
		$requiredVars = array_unique($requiredVars);
		// 3️⃣ Fetch saved mappings
		//  * If template has NO variables → no mapping required
		if (empty($requiredVars)) {
			return [
				'success' => true
			];
		}
		$mappedVars = WhatsAppTemplateFieldMapping::where('organization_id', $orgId)
			->where('template_id', $templateId)
			->where('crm_module', $module)
			->pluck('template_variable')
			->toArray();

		// 4️⃣ Find missing mappings
		$missing = array_diff($requiredVars, $mappedVars);
		if (!empty($missing)) {
			return [
				'success' => false,
				'message' => 'Template variable mapping missing',
				'missing_variables' => array_values($missing)
			];
		}
		// 5️⃣ All good
		return [
			'success' => true
		];
	}
	public function saveInteractive(array $data, string $orgId, string $userId)
	{
		$channel = WhatsAppChannel::where('id', $data['whatsapp_channel_id'])
			->where('organization_id', $orgId)
			->first();

		if (!$channel) {
			return [
				'success' => false,
				'message' => 'Invalid WhatsApp channel'
			];
		}
		$interactive = WhatsAppInteractive::updateOrCreate(
			['name' => $data['name']],
			[
				'id' => (string) Str::uuid(), // ⚠️ this will overwrite on update
				'organization_id' => $orgId,
				'whatsapp_channel_id' => $data['whatsapp_channel_id'],
				'name' => $data['name'],
				'type' => $data['type'],
				'body' => $data['body'],
				'crm_module' => $data['crm_module'] ?? null,
				'trigger_event' => $data['trigger_event'] ?? null,
				'is_active' => 1,
				'created_by' => $userId
			]
		);
		WhatsAppInteractiveItem::where('interactive_id', $interactive->id)->delete();
		foreach ($data['items'] as $item) {
			WhatsAppInteractiveItem::create([
				'id' => (string) Str::uuid(),
				'interactive_id' => $interactive->id,
				'organization_id' => $orgId,
				'item_type' => $item['item_type'],
				'item_key' => $item['item_key'],
				'title' => $item['title'],
				'description' => $item['description'] ?? null,
				'section' => $item['section'] ?? null,
				'sort_order' => $item['sort_order'] ?? 0,
				'next_action_type' => $item['next_action_type'] ?? null,
				'next_action_value' => $item['next_action_value'] ?? null,
			]);
		}

		return [
			'success' => true,
			'id' => $interactive->id
		];
	}
	public function buildInteractivePayload($interactive, $items): array
	{
		if ($interactive->type === 'button') {
			return [
				'type' => 'interactive',
				'interactive' => [
					'type' => 'button',
					'body' => ['text' => $interactive->body],
					'action' => [
						'buttons' => $items->map(function ($item) {
							return [
								'type' => 'reply',
								'reply' => [
									'id' => $item->item_key,
									'title' => $item->title
								]
							];
						})->values()->toArray()
					]
				]
			];
		}

		if ($interactive->type === 'list') {
			$sections = [];
			foreach ($items as $item) {
				$sections[$item->section][] = [
					'id' => $item->item_key,
					'title' => $item->title,
					'description' => $item->description
				];
			}

			return [
				'type' => 'interactive',
				'interactive' => [
					'type' => 'list',
					'body' => ['text' => $interactive->body],
					'action' => [
						'button' => 'Choose',
						'sections' => collect($sections)->map(function ($rows, $title) {
							return [
								'title' => $title,
								'rows' => array_values($rows)
							];
						})->values()->toArray()
					]
				]
			];
		}
		return [];
	}
	public function sendRawMessage(string $to, array $payload)
	{
		$data = [
			'messaging_product' => 'whatsapp',
			'to' => $to
		] + $payload;

		$url = "{$this->baseUrl}/{$this->phoneNumberId}/messages";
		return self::request($url, $this->accessToken, $data, 'POST');
	}
	public function resolveIncomingOwnerByNumber(string $fromNumber): array
	{
		$results = [];
		$modules = ['Contacts', 'Leads']; // 🔒 fixed scope

		foreach ($modules as $module) {

			try {
				$fieldManager = \App\Models\FieldModelManager::make($module, 'EditView');
			} catch (\Throwable $e) {
				continue;
			}

			// 🔎 Get only phone fields
			$phoneFields = collect($fieldManager->getFields())
				->filter(function ($field) {
					return in_array($field->getFieldDataType(), ['phone', 'mobile']);
				});

			if ($phoneFields->isEmpty()) {
				continue;
			}

			// Build OR WHERE dynamically
			$query = \DB::table(strtolower($module))
				->select('id');

			$query->where(function ($q) use ($phoneFields, $fromNumber) {
				foreach ($phoneFields as $field) {
					$q->orWhere($field->getFieldName(), $fromNumber);
				}
			});

			$records = $query->get();

			foreach ($records as $row) {
				foreach ($phoneFields as $field) {
					$results[] = [
						'module'     => $module,
						'record_id'  => $row->id,
						'crm_field'  => $field->getFieldName(),
						'crm_value'  => $fromNumber,
					];
				}
			}
		}
		return $results;
	}
	public function sendFlow(string $to, string $flowId, array $options = [])
	{
		try {
			$payload = $this->buildFlowPayload($to, $flowId, $options);

			$response = Http::withToken($this->accessToken)
				->post("{$this->baseUrl}/{$this->phoneNumberId}/messages", $payload);

			if ($response->successful()) {
				$result = $response->json();

				Log::info('WhatsApp Flow sent successfully', [
					'to' => $to,
					'flow_id' => $flowId,
					'message_id' => $result['messages'][0]['id'] ?? null
				]);

				return [
					'success' => true,
					'message_id' => $result['messages'][0]['id'] ?? null,
					'response' => $result
				];
			}

			throw new Exception('Failed to send flow: ' . $response->body());

		} catch (Exception $e) {
			Log::error('WhatsApp Flow send error', [
				'error' => $e->getMessage(),
				'to' => $to,
				'flow_id' => $flowId
			]);

			return [
				'success' => false,
				'error' => $e->getMessage()
			];
		}
	}
	private function buildFlowPayload(string $to, string $flowId, array $options)
	{
		$payload = [
			'messaging_product' => 'whatsapp',
			'recipient_type' => 'individual',
			'to' => $to,
			'type' => 'interactive',
			'interactive' => [
				'type' => 'flow',
				'header' => [
					'type' => 'text',
					'text' => $options['header_text'] ?? 'Complete Your Request'
				],
				'body' => [
					'text' => $options['body_text'] ?? 'Please fill out the form below'
				],
				'action' => [
					'name' => 'flow',
					'parameters' => [
						'flow_message_version' => '3',
						'flow_token' => $options['flow_token'] ?? $this->generateFlowToken(),
						'flow_id' => $flowId,
						'flow_cta' => $options['flow_cta'] ?? 'Continue',
						'flow_action' => $options['flow_action'] ?? 'navigate',
					]
				]
			]
		];

		// Add footer if provided
		if (isset($options['footer_text'])) {
			$payload['interactive']['footer'] = [
				'text' => $options['footer_text']
			];
		}

		// Add flow action payload (initial data)
		if (isset($options['flow_action_payload'])) {
			$payload['interactive']['action']['parameters']['flow_action_payload'] = $options['flow_action_payload'];
		}

		// Add mode (draft or published)
		if (isset($options['mode'])) {
			$payload['interactive']['action']['parameters']['mode'] = $options['mode'];
		}

		return $payload;
	}
	private function generateFlowToken()
	{
		return uniqid('flow_', true) . '_' . time();
	}
	public function sendAppointmentFlow(string $to, string $flowId, array $userData = [])
	{
		return $this->sendFlow($to, $flowId, [
			'header_text' => '📅 Book Your Appointment',
			'body_text' => 'Select your preferred date and time for consultation',
			'footer_text' => 'Available slots are updated in real-time',
			'flow_cta' => 'Book Now',
			'flow_action_payload' => [
				'screen' => 'APPOINTMENT_SCREEN',
				'data' => array_merge([
					'user_id' => $userData['user_id'] ?? null,
					'user_name' => $userData['name'] ?? null,
					'user_email' => $userData['email'] ?? null,
				], $userData)
			]
		]);
	}




	public function findRecordByPhoneNumber(string $phoneNumber): array
	{
		// 1. Normalize phone number (digits only)
		$searchNumber = preg_replace('/[^0-9]/', '', $phoneNumber);

		// 2. Modules to search
		$modules = ['Contact', 'Lead'];
		$results = [];

		foreach ($modules as $module) {
			try {
				// 3. Get phone fields
				$fieldManager = \App\Models\FieldModelManager::make($module, 'EditView');
				$fields = $fieldManager->getFields();
				
				$phoneFields = [];
				foreach ($fields as $field) {
					if (in_array($field->getFieldType(), ['phone', 'mobile'])) {
						$phoneFields[] = $field->getFieldName(); // DB column name
					}
				}

				if (empty($phoneFields)) {
					continue;
				}

				// 4. Build Query
				// Assume table name is snake_case plural of module.
				$tableName = strtolower(\Illuminate\Support\Str::plural($module));
				
				$query = DB::table($tableName)
					->where('deleted', 0);
				
				$query->where(function ($q) use ($phoneFields, $searchNumber) {
					foreach ($phoneFields as $field) {
						// Using LIKE for partial match
						$q->orWhere($field, 'LIKE', "%{$searchNumber}%");
					}
				});

				// Fetch ALL matching records
				$records = $query->get();

				foreach ($records as $record) {
					// Find which specific field matched to return correct crm_field
					$matchedField = null;
					foreach ($phoneFields as $field) {
						$val = preg_replace('/[^0-9]/', '', $record->{$field} ?? '');
						if (!empty($val) && (str_contains($val, $searchNumber) || str_contains($searchNumber, $val))) {
							$matchedField = $field;
							break;
						}
					}

					$results[] = [
						'related_module' => $module,
						'related_id'     => $record->id,
						'crm_field'      => $matchedField ?? $phoneFields[0],
					];
				}

			} catch (\Throwable $e) {
				Log::warning("WhatsApp Lookup Failed for module {$module}: " . $e->getMessage());
				continue;
			}
		}

		return $results;
	}
	public function renderInteractiveMessageForLog(WhatsAppInteractive $interactive, $items): string
	{
		$output = [];
		$output[] = $interactive->body;

		if ($interactive->type === 'button') {
			foreach ($items as $item) {
				$output[] = '[Button] ' . $item->title;
			}
		}

		if ($interactive->type === 'list') {
			$sections = [];
			foreach ($items as $item) {
				$sections[$item->section][] = $item->title;
			}

			foreach ($sections as $sectionTitle => $rows) {
				if (!empty($sectionTitle)) {
					$output[] = '--- ' . $sectionTitle . ' ---';
				}
				foreach ($rows as $rowTitle) {
					$output[] = '• ' . $rowTitle;
				}
			}
		}

		return implode("\n\n", array_filter($output));
	}
	public function renderTemplateMessageForLog(
		array $templateComponents,   // from whatsapp_templates.components
		array $sendComponents         // $build['components']
	): string {
		$output = [];
		$paramMap = [];

		/** Step 1: Build parameter map */
		foreach ($sendComponents as $comp) {
			if (($comp['type'] ?? '') !== 'body') {
				continue;
			}

			foreach ($comp['parameters'] ?? [] as $index => $param) {
				if (!empty($param['parameter_name'])) {
					// NAMED
					$paramMap['{{' . $param['parameter_name'] . '}}'] = $param['text'];
				} else {
					// POSITIONAL {{1}}, {{2}}
					$paramMap['{{' . ($index + 1) . '}}'] = $param['text'];
				}
			}
		}

		/** Step 2: Render template */
		foreach ($templateComponents as $component) {

			/* HEADER */
			if ($component['type'] === 'HEADER' && ($component['format'] ?? '') === 'TEXT') {
				$output[] = $component['text'];
			}

			/* BODY */
			if ($component['type'] === 'BODY') {
				$text = $component['text'];

				// Replace variables
				foreach ($paramMap as $key => $value) {
					$text = str_replace($key, $value, $text);
				}

				$output[] = trim($text);
			}

			/* BUTTONS */
			if ($component['type'] === 'BUTTONS') {
				foreach ($component['buttons'] ?? [] as $btn) {
					$output[] = '[Button] ' . ($btn['text'] ?? '');
				}
			}
		}

		return implode("\n\n", array_filter($output));
	}

}

