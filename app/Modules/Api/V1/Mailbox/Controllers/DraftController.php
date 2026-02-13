<?php

namespace App\Modules\Api\V1\Mailbox\Controllers;

use App\Http\Controllers\ApiController;
use Illuminate\Http\Request;
use App\Services\Mail\MailboxService;
use Illuminate\Support\Facades\Validator;
class DraftController extends ApiController
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
            $drafts = $this->mailboxService->listDrafts($orgId, $userId, $mailServerId);
            return $this->success($drafts, 'Drafts retrieved');
        } catch (\Throwable $e) {
            return $this->errorFromException($e, 'Failed to retrieve drafts');
        }
    }

    public function store(Request $request)
    {
        $orgId = auth()->user()->organization_id;
        $userId = auth()->id();

        $values = $request->input('data.values');
        if (!$values) {
            $values = $request->all(); // Fallback
        }

        $validator = Validator::make($values, [
            'mail_server_id' => 'required|uuid',
            'to' => 'nullable|array',
            'cc' => 'nullable|array',
            'bcc' => 'nullable|array',
            'subject' => 'nullable|string',
            'body' => 'nullable|string',
            'reply_to_mail_log_id' => 'nullable|uuid',
            'forward_from_mail_log_id' => 'nullable|uuid',
            'related_module' => 'nullable|string',
            'related_record_id' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return $this->error(collect($validator->errors()->all())->implode(','));
        }
        
        $data = $validator->validated();

        // Resolve Recipients if related module is present
        if (!empty($data['related_module']) && !empty($data['related_record_id'])) {
            $fields = ['to', 'cc', 'bcc'];
            foreach ($fields as $field) {
                if (!empty($data[$field])) {
                    $resolution = $this->mailboxService->resolveRecipients(
                        $data[$field],
                        $data['related_module'],
                        $data['related_record_id'],
                        $orgId
                    );
                    
                    if (!empty($resolution['errors'])) {
                        return $this->error(implode(', ', $resolution['errors']));
                    }
                    
                    $data[$field] = $resolution['emails'];
                }
            }
        }

        try {
            $draft = $this->mailboxService->createDraft($orgId, $userId, $data);
            return $this->success($draft, 'Draft created');
        } catch (\Throwable $e) {
            return $this->errorFromException($e, 'Failed to create draft');
        }
    }

    public function show($id)
    {
        $orgId = auth()->user()->organization_id;

        try {
            $draft = $this->mailboxService->getDraft($id, $orgId);
            return $this->success($draft, 'Draft details');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->error('Draft not found');
        } catch (\Throwable $e) {
            return $this->errorFromException($e, 'Failed to fetch draft');
        }
    }

    public function update(Request $request, $id)
    {
        $orgId = auth()->user()->organization_id;

        $values = $request->input('data.values');
        if (!$values) {
            $values = $request->all();
        }

        $validator = Validator::make($values, [
            'mail_server_id' => 'nullable|uuid',
            'to' => 'nullable|array',
            'cc' => 'nullable|array',
            'bcc' => 'nullable|array',
            'subject' => 'nullable|string',
            'body' => 'nullable|string',
            'reply_to_mail_log_id' => 'nullable|uuid',
            'forward_from_mail_log_id' => 'nullable|uuid', 
            'related_module' => 'nullable|string',
            'related_record_id' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return $this->error(collect($validator->errors()->all())->implode(','));
        }
        
        $data = $validator->validated();

        // Resolve Recipients if related module is present
        if (!empty($data['related_module']) && !empty($data['related_record_id'])) {
            $fields = ['to', 'cc', 'bcc'];
            foreach ($fields as $field) {
                if (!empty($data[$field])) {
                    $resolution = $this->mailboxService->resolveRecipients(
                        $data[$field],
                        $data['related_module'],
                        $data['related_record_id'],
                        $orgId
                    );
                    
                    if (!empty($resolution['errors'])) {
                        return $this->error(implode(', ', $resolution['errors']));
                    }
                    
                    $data[$field] = $resolution['emails'];
                }
            }
        }

        try {
            $draft = $this->mailboxService->updateDraft($id, $orgId, $data);
            return $this->success($draft, 'Draft updated');
        } catch (\Throwable $e) {
            return $this->errorFromException($e, 'Failed to update draft');
        }
    }

    public function destroy($id)
    {
        $orgId = auth()->user()->organization_id;

        try {
            $this->mailboxService->deleteDraft($id, $orgId);
            return $this->success([], 'Draft deleted');
        } catch (\Throwable $e) {
            return $this->errorFromException($e, 'Failed to delete draft');
        }
    }
}
