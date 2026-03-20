<?php


namespace App\Modules\Api\V1\WhatsApp\Controllers;

use App\Modules\Api\V1\WhatsApp\Models\WhatsAppChannel;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Controllers\ApiController;
use App\Services\WhatsApp\WhatsAppApiService;
use App\Traits\ResultTrait;
use Illuminate\Support\Str;
use Carbon\Carbon;

class AccountController extends Controller
{
	use ResultTrait;
	public function accountsInfo()
	{
		return response()->json([
			'business_name'   => 'Demo Business',
			'whatsapp_number' => '+91XXXXXXXXXX',
			'waba_status'     => 'APPROVED'
		]);
	}
	public function getByOrg(){
		$orgId = auth()->user()->organization_id;

		$accounts = WhatsAppChannel::where('organization_id', $orgId)
			->where('is_active', true)
			->get(); // ✅ coll

		if (!$accounts) {
			return $this->error('WhatsApp account not configured');
		}
		$values = $accounts->map(function ($account) {
			return [
				'id'              => $account->id,
				'name'            => $account->name,
				'description'     => $account->desc,
				'app_id'          => $account->app_id,
				'phone_number_id' => $account->phone_number_id,
				'business_id'     => $account->business_id,
				'is_active'       => $account->is_active,
			];
		});
		return $this->success([
			'values' => $values,
		]);
	}

	public function save(Request $request)
	{
		$data = $request->input('data.values', []);
		
		$orgId = auth()->user()->organization_id;

		$service = new WhatsAppApiService($orgId);

		$result = $service->saveAccount($orgId, $data);

		if ($result['success'] === false) {
			return $this->error($result['message']);
		}

		return $this->success([
			'channel' => $result['channel']
		], 'WhatsApp account connected successfully');
	}
	public function delete(string $channelId)
	{
		$orgId = auth()->user()->organization_id;

		$service = new WhatsAppApiService($orgId);

		$result = $service->deleteAccount($orgId, $channelId);

		if ($result['success'] === false) {
			return $this->error($result['message']);
		}

		return $this->success([], $result['message']);
	}
	public function healthCheck()
	{
		$orgId = auth()->user()->organization_id;
		$exists = WhatsAppChannel::where('organization_id', $orgId) 
			  ->where('is_active', true)
			->exists();
		return response()->json([
			'data' => [
				'values' => [
					'connected' => $exists
				]
			]
		]);
	}

}
