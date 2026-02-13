<?php

namespace App\Modules\Api\V1\Mailbox\Controllers;

use App\Http\Controllers\ApiController;
use Illuminate\Http\Request;
use App\Modules\Api\V1\Mailbox\Models\MailLabel;
use App\Services\Mail\MailboxService;
use Illuminate\Support\Facades\Validator;

class LabelController extends ApiController
{
    protected $mailboxService;

    public function __construct(MailboxService $mailboxService)
    {
        $this->mailboxService = $mailboxService;
    }

    public function listByServer(string $mailServerId)
    {
        $orgId = auth()->user()->organization_id;

        $validator = Validator::make(['mail_server_id' => $mailServerId], [
            'mail_server_id' => 'required|uuid',
        ]);

        
        if ($validator->fails()) {
            return $this->error(collect($validator->errors()->all())->implode(','));
        }

        try {
            $labels = $this->mailboxService->listLabels($orgId, $mailServerId);
            return $this->success($labels, 'Labels retrieved');
        } catch (\Throwable $e) {
            return $this->errorFromException($e, 'Failed to retrieve labels');
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
            'color' => 'nullable|string|max:7',
            'description' => 'nullable|string|max:255'
        ]);

        if ($validator->fails()) {
            return $this->error(collect($validator->errors()->all())->implode(','));
        }

        try {
            $label = $this->mailboxService->createLabel($orgId, $userId, $validator->validated());
            return $this->success($label, 'Label created');
        } catch (\Throwable $e) {
            return $this->errorFromException($e, 'Failed to create label');
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
            'color' => 'nullable|string|max:7',
            'description' => 'nullable|string|max:255',
            'mail_server_id' => 'nullable|uuid'
        ]);

        if ($validator->fails()) {
            return $this->error(collect($validator->errors()->all())->implode(','));
        }

        try {
            $label = $this->mailboxService->updateLabel($id, $orgId, $validator->validated());
            return $this->success($label, 'Label updated');
        } catch (\Throwable $e) {
            return $this->errorFromException($e, 'Failed to update label');
        }
    }

    public function destroy($id)
    {
        $orgId = auth()->user()->organization_id;

        $validator = Validator::make(['id' => $id], [
            'id' => 'required|uuid',
        ]);

        if ($validator->fails()) {
            return $this->error(collect($validator->errors()->all())->implode(','));
        }

        $id = $validator->validated()['id'];    

        try {
            $this->mailboxService->deleteLabel($id, $orgId);
            return $this->success([], 'Label deleted');
        } catch (\Throwable $e) {
            return $this->errorFromException($e, 'Failed to delete label');
        }
    }
}
