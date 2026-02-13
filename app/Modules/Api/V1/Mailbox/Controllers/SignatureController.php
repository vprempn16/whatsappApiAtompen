<?php

namespace App\Modules\Api\V1\Mailbox\Controllers;

use App\Http\Controllers\ApiController;
use Illuminate\Http\Request;
use App\Services\Mail\MailboxService;
use Illuminate\Support\Facades\Validator;

class SignatureController extends ApiController
{
    protected $mailboxService;

    public function __construct(MailboxService $mailboxService)
    {
        $this->mailboxService = $mailboxService;
    }

    public function index(Request $request)
    {
        $orgId = auth()->user()->organization_id;
        $userId = auth()->id();
        $mailServerId = $request->input('mail_server_id');

        try {
            $signatures = $this->mailboxService->listSignatures($orgId, $userId, $mailServerId);
            return $this->success($signatures, 'Signatures retrieved');
        } catch (\Throwable $e) {
            return $this->errorFromException($e, 'Failed to retrieve signatures');
        }
    }

    public function store(Request $request)
    {
        $orgId = auth()->user()->organization_id;
        $userId = auth()->id();

        $values = $request->input('data.values');
        if (!$values) {
            return $this->error('Invalid payload structure');
        }

        $validator = Validator::make($values, [
            'mail_server_id' => 'required|uuid',
            'name' => 'required|string|max:100',
            'content' => 'required|string',
            'is_default' => 'boolean'
        ]);

        if ($validator->fails()) {
            return $this->error(collect($validator->errors()->all())->implode(','));
        }

        try {
            $signature = $this->mailboxService->createSignature($orgId, $userId, $validator->validated());
            return $this->success($signature, 'Signature created');
        } catch (\Throwable $e) {
            return $this->errorFromException($e, 'Failed to create signature');
        }
    }

    public function update(Request $request, $id)
    {
        $orgId = auth()->user()->organization_id;

        $values = $request->input('data.values');
        if (!$values) {
            return $this->error('Invalid payload structure');
        }

        $validator = Validator::make($values, [
            'name' => 'nullable|string|max:100',
            'content' => 'nullable|string',
            'is_default' => 'boolean',
            'mail_server_id' => 'nullable|uuid'
        ]);

        if ($validator->fails()) {
            return $this->error(collect($validator->errors()->all())->implode(','));
        }

        try {
            $signature = $this->mailboxService->updateSignature($id, $orgId, $validator->validated());
            return $this->success($signature, 'Signature updated');
        } catch (\Throwable $e) {
            return $this->errorFromException($e, 'Failed to update signature');
        }
    }

    public function destroy($id)
    {
        $orgId = auth()->user()->organization_id;

        try {
            $this->mailboxService->deleteSignature($id, $orgId);
            return $this->success([], 'Signature deleted');
        } catch (\Throwable $e) {
            return $this->errorFromException($e, 'Failed to delete signature');
        }
    }
}
