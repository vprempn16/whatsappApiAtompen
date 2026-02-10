<?php

namespace App\Modules\Api\V1\Mail\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Models\FieldModelManager;
use App\Http\Controllers\ApiController;
use App\Traits\ResultTrait;
use App\Services\Mail\MailService;
use Illuminate\Support\Facades\Validator;
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

        $values = $request->input('data.values');

        if (!$values) {
            return $this->error('Invalid payload structure');
        }
       

        $validator = Validator::make($values, [
            'name'       => 'required|string|max:100',
            'description'=> 'nullable|string',
            'username'   => 'required|string|max:100',
            'password'   => 'required|string',
            'host'       => 'required|string',
            'port'       => 'required|integer',
            'encryption' => 'required|string|in:ssl,tls',
            'from_email' => 'required|email',
            'from_name' => 'required|string',
            'mail_type' => 'required|string',
        ]);

        if ($validator->fails()) {
            $message = collect($validator->errors()->all())->implode(',');
            return $this->error($message);
        }

        
        $data = $validator->validated();
        $data['organization_id'] = $orgId;
        $data['created_by'] = $userId;
        $data['from_name'] = $data['from_name'];
        $data['mail_type'] = $data['mail_type'];
        
        try {
            $server = $this->service->createOutgoingServer($data);
            return $this->success($server, 'Mail server created successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function update($id, Request $request)
    {
        $orgId = auth()->user()->organization_id;

        if (!$orgId) {
            return $this->error('Organization not found');
        }

        $values = $request->input('data.values'); // Consistent payload

        if (!$values) {
            return $this->error('Invalid payload structure');
        }

        $validator = Validator::make($values, [
            'name'       => 'required|string|max:100',
            'description'=> 'nullable|string',
            'username'   => 'required|string|max:100',
            'password'   => 'required|string', // Optional on update
            'host'       => 'required|string',
            'port'       => 'required|integer',
            'encryption' => 'required|string|in:ssl,tls',
            'from_email' => 'required|email',
            'from_name' => 'required|string',
            'mail_type' => 'required|string',
        ]);

        if ($validator->fails()) {
            $message = collect($validator->errors()->all())->implode(',');
            return $this->error($message);
        }

        $data = $validator->validated();
        $data['organization_id'] = $orgId;
        $data['from_name'] = $values['from_name'];
        $data['mail_type'] = $values['mail_type'];

        try {
            $server = $this->service->updateOutgoingServer($id, $data);
            return $this->success($server, 'Mail server updated successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function show($id)
    {
        $orgId = auth()->user()->organization_id;

        if (!$orgId) {
            return $this->error('Organization not found');
        }

        try {
            $server = $this->service->getOutgoingServer($id, $orgId);
            return $this->success($server, 'Mail server details fetched');
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

	    try {
		    $this->service->setOutgoingServer($id, $orgId);
		    return $this->success([], 'Outgoing server configured');
	    } catch (\Exception $e) {
		    return $this->error($e->getMessage());
	    }
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


