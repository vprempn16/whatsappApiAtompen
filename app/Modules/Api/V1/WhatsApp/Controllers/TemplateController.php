<?php

namespace App\Modules\Api\V1\WhatsApp\Controllers;

use App\Modules\Api\V1\WhatsApp\Models\WhatsAppTemplateFieldMapping;
use App\Http\Controllers\Controller;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Models\FieldModelManager;
use App\Http\Controllers\ApiController;
use App\Traits\ResultTrait;
use App\Services\CRM\RecordObject;
use App\Modules\Api\V1\WhatsApp\Models\WhatsAppTemplate;
use App\Services\WhatsApp\WhatsAppApiService;
use App\Modules\Api\V1\WhatsApp\Models\WhatsAppChannel;


class TemplateController extends ApiController{
	use ResultTrait;

	public function listCheck(){
		$orgId = auth()->user()->organization_id;

		if (!$orgId) {
			return $this->error('Organization not found');
		}

		$exists = WhatsAppTemplate::where('organization_id', $orgId)->exists();

		return $this->success([
			'has_templates' => $exists
		]);

	}
	public function syncTemplates(Request $request, string $channelId)
	{
		$orgId = auth()->user()->organization_id;

		$service = new WhatsAppApiService($orgId, $channelId);

		if ($service->noService) {
			return $this->error('Invalid or inactive WhatsApp channel');
		}

		$validate = $service->validateAccount();
		if ($validate['success'] === false) {
			return $this->error($validate['message']);
		}

		$result = $service->fetchAndSyncTemplates($orgId, $channelId);

		if ($result['success'] === false) {
			return $this->error($result['message']);
		}

		return $this->success([
			'message' => $result['message'],
			'templates' => $result['templates']
		]);
	}

	public function getWhatsAppTemplates(Request $request,string $channelId)
	{
		$data = $request->input('data.values', []);	

		$orgId = auth()->user()->organization_id;

		 $module = $request->query('module'); //  (optional)

		if ($orgId == '') {
			return $this->error('organization_id required');
		}

                if ($channelId == '') {
                        return $this->error('WhatsApp channel is required');
                }

		$service = new WhatsAppApiService($orgId,$channelId);

		if($service->noService){
			 return $this->error('This Organization has no service');
		}
		
		$validate = $service->validateAccount();

                if($validate['success'] === false){
                        return $this->error($validate['message']);
		}
		if(!empty($module)){
			$response = $service->getTemplatesByModule($orgId,$channelId,$module );
		}else{
			$response = $service->getTemplates($orgId,$channelId);
		}
		if($response['success'] === false){
			 return $this->error($result['message']);
		}
		return $this->success($response['values']);
	}
	public function getMappingTemplates(Request $request){

		$organizationId = $request->get('organization_id');

		$templates = WhatsAppTemplateFieldMapping::where(
			'organization_id',
			$organizationId
		)->get();

		$values = $templates->map(function ($row) {
			return [
				'id'                => $row->id,
				'template_name'     => $row->template_name,
				'language'          => $row->template_language,
				'template_variable' => $row->template_variable,
				'crm_module'        => $row->crm_module,
				'crm_field'         => $row->crm_field,
			];
		});

		return response()->json([
			'data' => [
				'values' => $values
			]
		]);
	}
	public function getTemplateByName(Request $request){
		$values = $request->input('data.values');

		$orgId = auth()->user()->organization_id;
			
		$request->validate([
			'data.values.template_name'   => 'required|string',
		]);
		$channelId = $values['channelId'];

		if(empty($channelId)){
			return $this->error('WhatsApp channel is required');
		}
		$service = new WhatsAppApiService($orgId,$channelId);

                if($service->noService){
                         return $this->error('This Organization has no service or Invalid account');
                }
		$result = $service->getTemplateUsingName(
			$values['template_name']
		);
		if (!$result['success']) {
			return $this->error($result['message']);
		}
		return $this->success($result['data']);
	}

	public function getTemplateMapping(Request $request,string $id = null){
		$orgId = auth()->user()->organization_id;
		$mapping = WhatsAppTemplateFieldMapping::where(
			'id', $id
		)->where(
			'organization_id', $values['organization_id']
		)->get();

		return response()->json([
			'data' => [
				'values' => $mapping
			]
		]);
	}
	public function getMappingTemplateByName(Request $request,string $templateName,string $module ) {
		$orgId = auth()->user()->organization_id;

		$mapping = WhatsAppTemplateFieldMapping::where('organization_id', $orgId)
			->where('template_name', $templateName)
			->where('module', $module) // ✅ NEW condition
			->get();

	 	if($mapping->isEmpty()){
			 return $this->error("No Mapping for this Template");
		}
		return $this->success($mapping);
	}
	public function save(Request $request, string $channelId, string $templateId)
	{
		$orgId = auth()->user()->organization_id;
		$data  = $request->input('data.values', []);

		$service = new WhatsAppApiService($orgId, $channelId);
		if ($service->noService) {
			return $this->error('This Organization has no service or Invalid account');
		}

		$result = $service->saveMapping($orgId, $channelId, $templateId, $data);

		if (!$result['status']) {
			return $this->error($result['message']);
		}

		if ($result['type'] === 'init') {
			return $this->success([
				'values' => $result['data']
			]);
		}

		return $this->success($result['message']);
	}
	public function templateMapping(
		Request $request,
		string $channelId,
		string $templateId
	) {
		$orgId = auth()->user()->organization_id;

		$service = new WhatsAppApiService($orgId, $channelId);

		if ($service->noService) {
			return $this->error('Invalid or inactive WhatsApp channel');
		}

		try {
			if ($request->isMethod('get')) {
				$result = $service->getTemplateMapping($templateId);
			} else {
				$data = $request->input('data.values', []);
				$result = $service->updateTemplateMapping($templateId, $data);
			}

			return $this->success($result);

		} catch (\Throwable $e) {
			return $this->error($e->getMessage());
		}
	}
	public function syncSingleTemplate(
		Request $request,
		string $channelId,
		string $templateId
	) {
		$orgId = auth()->user()->organization_id;

		$service = new WhatsAppApiService($orgId, $channelId);

		if ($service->noService) {
			return $this->error('Invalid or inactive WhatsApp channel');
		}

		try {
			$result = $service->syncSingleTemplate($templateId);
			return $this->success($result);

		} catch (\Throwable $e) {
			return $this->error($e->getMessage());
		}
	}


	public function previewTemplate(Request $request,string $module ,string $recordId,string $channelId,string $templateId) {
		$orgId = auth()->user()->organization_id;
		$template = WhatsAppTemplate::where('organization_id', $orgId)
			->where('whatsapp_channel_id', $channelId)
			->where('id', $templateId)
			->where('module', $module)
			->first();
		
		if (!$templateId) {
			return $this->error('Template not found');
		}
		$service = new WhatsAppApiService($orgId, $channelId);
                if ($service->noService) {
                        return $this->error('Invalid or inactive WhatsApp channel');
		}

		$check = $service->validateTemplateMappings($orgId,$templateId,$module);

		if ($check['success'] === false) {
			return $this->error($check['message'] . ' Missing variables: ' . implode(', ', $check['missing_variables'] ?? []));
		}


		$record = RecordObject::make($module, $recordId);
		if (!$record) {
			return $this->error('CRM record not found');
		}

		$mappings = WhatsAppTemplateFieldMapping::where('organization_id', $orgId)
			->where('template_id', $templateId)
			->get();

		$fieldManager = FieldModelManager::make($module, 'DetailView');
		$valueMap = [];

		foreach ($mappings as $map) {
			$fieldModel = $fieldManager->getFieldModel($map->crm_field);
			if (!$fieldModel) {
				continue;
			}

			$fieldName = $fieldModel->getFieldName();
			$valueMap[$map->template_variable] = (string) ($record->{$fieldName} ?? '');
		}

		$preview = [];

		foreach ($template->components as $component) {

			/* HEADER */
			if ($component['type'] === 'HEADER' && ($component['format'] ?? '') === 'TEXT') {
				$preview[] = [
					'type' => 'header',
					'text' => $this->replaceVars($component['text'], $valueMap)
				];
			}

			/* BODY */
			if ($component['type'] === 'BODY') {
				$preview[] = [
					'type' => 'body',
					'text' => $this->replaceVars($component['text'], $valueMap)
				];
			}

			/* BUTTONS */
			if ($component['type'] === 'BUTTONS') {
				foreach ($component['buttons'] as $index => $btn) {

					$button = [
						'type'  => 'button',
						'index' => $index,
						'text'  => $btn['text'],
						'button_type' => $btn['type'] // URL / PHONE_NUMBER / QUICK_REPLY
					];

					if ($btn['type'] === 'URL' && !empty($btn['url'])) {
						$button['url'] = $this->replaceVars($btn['url'], $valueMap);
					}

					if ($btn['type'] === 'PHONE_NUMBER' && !empty($btn['phone_number'])) {
						$button['phone_number'] = $btn['phone_number'];
					}

					$preview[] = $button;
				}
			}
		}
		return $this->success([
			'templateId' => $templateId,
			'module'   => $module,
			'recordId'=> $recordId,
			'preview'  => $preview
		]);
	}
	private function replaceVars(string $text, array $values): string
	{
		foreach ($values as $key => $val) {
			// Named {{name}}
			$text = str_replace('{{'.$key.'}}', $val, $text);

			// Positional {{1}}
			if (is_numeric($key)) {
				$text = str_replace('{{'.$key.'}}', $val, $text);
			}
		}
		return $text;
	}
}
