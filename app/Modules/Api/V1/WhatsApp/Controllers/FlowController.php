<?php
namespace App\Modules\Api\V1\WhatsApp\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Models\FieldModelManager;
use App\Http\Controllers\ApiController;
use App\Traits\ResultTrait;
use App\Services\CRM\RecordObject;
use App\Services\WhatsApp\WhatsAppApiService;
use App\Modules\Api\V1\WhatsApp\Models\WhatsAppChannel;
use Illuminate\Support\Facades\Validator;


class FlowController  extends Controller{
use ResultTrait;

    /**
     * Send appointment booking flow
     */
    public function sendAppointmentFlow(Request $request)
    {
	$values = $request->input('data.values', []);
        $validator = Validator::make($values, [
            'phone_number' => 'required|string',
            'flow_id' => 'required|string',
            'user_data' => 'nullable|array',
            'user_data.user_id' => 'nullable|string',
            'user_data.name' => 'nullable|string',
            'user_data.email' => 'nullable|email',
        ]);
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

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $result = $service->sendAppointmentFlow(
            $values['phone_number'],
            $values['flow_id'],
            $values['user_data'] ?? []
        );

        return response()->json($result);
    }

}
