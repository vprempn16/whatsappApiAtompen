<?php

namespace App\Modules\Api\V1\Mail\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Models\FieldModelManager;
use App\Http\Controllers\ApiController;
use App\Traits\ResultTrait;
use App\Services\Mail\MailService;
class MailServerController extends Controller
{
    use ResultTrait;

    protected $service;

    public function __construct(MailService $service)
    {
        $this->service = $service;
    }
    public function inbox($id)
    {
	    return $this->success(
		    $this->service->fetchIncoming($id),
		    'Inbox fetched'
	    );
    }
    public function index()
    {
        $orgId = auth()->user()->organization_id;

        if (!$orgId) {
            return $this->error('Organization not found');
        }

        $servers = \App\Modules\Api\V1\Mail\Models\MailServer::where('organization_id', $orgId)
            ->where('deleted', 0)
            ->orderBy('created_at', 'desc')
            ->get();

        return $this->success($servers, 'Mail servers retrieved');
    }

    public function store(Request $request)
    {
        $orgId = auth()->user()->organization_id;
        $userId = auth()->id();

        if (!$orgId) {
            return $this->error('Organization not found');
        }

        $data = $request->all();
        $data['organization_id'] = $orgId;
        $data['created_by'] = $userId;

        try {
            $server = $this->service->saveServer($data);
            return $this->success($server, 'Mail server saved successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function destroy($id)
    {
        $orgId = auth()->user()->organization_id;

        if (!$orgId) {
            return $this->error('Organization not found');
        }

        $result = $this->service->deleteServer($id, $orgId);

        if (!$result) {
            return $this->error('Server not found or already deleted');
        }

        return $this->success([], 'Mail server deleted successfully');
    }

    public function setOutgoing($id)
    {
        $orgId = auth()->user()->organization_id;

        if (!$orgId) {
            return $this->error('Organization not found');
        }

        $this->service->setOutgoingServer($id, $orgId);

        return $this->success([], 'Outgoing server configured');
    }

    public function connect($id)
    {
        $orgId = auth()->user()->organization_id;

        if (!$orgId) {
            return $this->error('Organization not found');
        }

        $result = $this->service->connectServer($id, $orgId);

        if ($result['status'] === 'failed') {
            return $this->error($result['error']);
        }

        return $this->success([], 'Server connected successfully');
    }
}


