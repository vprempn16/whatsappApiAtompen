<?php

namespace App\Modules\Api\V1\Mailbox\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\Mail\MailboxService;
use App\Services\Mail\MailService;
use App\Traits\ResultTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
class MailboxController extends Controller
{
    use ResultTrait;

    protected $mailboxService;
    protected $mailService;

    public function __construct(MailboxService $mailboxService, MailService $mailService)
    {
        $this->mailboxService = $mailboxService;
        $this->mailService = $mailService;
    }

    /**
     * Get Inbox Emails
     */
    public function index(Request $request)
    {
        $orgId = auth()->user()->organization_id;
        $userId = auth()->id();

        if (!$orgId) {
            return $this->error('Organization not found');
        }

        $values = $request->input('data.values');
        if (!$values) {
            $values = $request->all(); // Fallback
        }

        $filters = Validator::make($values, [
            'folder_id'      => 'nullable|uuid',
            'mail_server_id' => 'required|uuid',
            'is_read'        => 'nullable|boolean',
            'is_starred'     => 'nullable|boolean',
            'search'         => 'nullable|string',
            'limit'          => 'nullable|integer|min:1|max:100',
        ]);
        $filters = $filters->validated();
        try {
            $emails = $this->mailboxService->getInbox(
                $orgId,
                $userId,
                $filters,
                $filters['limit'] ?? 20
            );

            return $this->success($emails, 'Inbox fetched successfully');
        } catch (\Throwable $e) {
            return $this->error('Failed to fetch inbox: ' . $e->getMessage());
        }
    }

    /**
     * Get Single Email
     */
    public function show($id)
    {
        $orgId = auth()->user()->organization_id;

        if (!$orgId) {
            return $this->error('Organization not found');
        }

        $values = $request->input('data.values');
        if (!$values) {
            $values = $request->all(); // Fallback
        }

        $validator = Validator::make($values, [
            'id' => 'required|uuid',
        ]);

        if ($validator->fails()) {
            return $this->error(collect($validator->errors()->all())->implode(','));
        }

        $id = $validator->validated()['id'];    
        try {
            $email = $this->mailboxService->getEmail($id, $orgId);
            return $this->success($email, 'Email details fetched');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->error('Email not found');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }


    /**
     * Send New Email (Composer)
     */
    public function send(Request $request)
    {
        $orgId = auth()->user()->organization_id;
        if (!$orgId) return $this->error('Organization not found');

        $values = $request->input('data.values');
        
        if (!$values) {
            $values = $request->all(); 
        }

        // Map 'recipients' to 'to' if present
        if (isset($values['recipients'])) {
            if (is_array($values['recipients'])) {
                $values['to'] = $values['recipients']; 
            } else {
                $values['to'] = $values['recipients'];
            }
        }
    
        $validator = Validator::make($values, [
            'server_id' => 'required|uuid',
            'to' => 'required', // Allow array or string
            'subject' => 'nullable|string',
            'body' => 'nullable|string',
            'cc' => 'nullable|array',
            'bcc' => 'nullable|array',
            'in_reply_to' => 'nullable|string',
            'references' => 'nullable|string',
            'folder_id' => 'nullable|uuid'
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first());
        }

        $data = $validator->validated();

        // Handle File Uploads (files are likely outside data.values structure in standard multipart/form-data, 
        // but if JSON payload, files might be base64? 
        // User request implies JSON payload structure. File uploads usually handled separately or separate implementation.
        // Assuming strictly JSON payload for now as per user snippet. 
        // If multipart, data.values would be a JSON string field?)
        
        // Let's assume standard Laravel request handling for now. 
        // If keys are top level, code 68-128 handles files.
        // I will keep file handling logic but adapting to use $data array.

        $attachments = [];
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                 $path = $file->store('mail-attachments/' . $orgId, 'local');
                 $attachments[] = [
                     'path' => storage_path('app/' . $path),
                     'name' => $file->getClientOriginalName(),
                     'original_name' => $file->getClientOriginalName(),
                     'mime_type' => $file->getMimeType(),
                     'size' => $file->getSize(),
                     'disk' => 'local'
                 ];
            }
        }
        $data['attachments'] = $attachments;

        // Use existing MailService logic
        try {
            $result = $this->mailService->sendMail($data);
            
            if ($result['status'] === false) {
                 return $this->error($result['error']);
            }
            
            return $this->success($result['data'], 'Email sent successfully');

        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * Bulk Actions
     */
    public function bulkAction(Request $request)
    {
        $orgId = auth()->user()->organization_id;

        if (!$orgId) {
            return $this->error('Organization not found');
        }

        $values = $request->input('data.values');
        if (!$values) {
            $values = $request->all();
        }

        $validator = Validator::make($values, [
            'ids'            => 'required|array|min:1',
            'ids.*'          => 'uuid',
            'action'         => 'required|string|in:delete,archive,restore,star,unstar,read,unread,move,permanent_delete',
            'params'         => 'nullable|array',
            'params.folder_id' => 'required_if:action,move|uuid'
        ]);

        if ($validator->fails()) {
            return $this->error(collect($validator->errors()->all())->implode(','));
        }

        $data = $validator->validated();

        try {
            $this->mailboxService->bulkAction(
                $data['ids'],
                $data['action'],
                $orgId,
                $data['params'] ?? []
            );

            return $this->success([], 'Bulk action applied successfully');
        } catch (\Throwable $e) {
            return $this->error('Failed to perform bulk action: ' . $e->getMessage());
        }
    }

    /**
     * Compose Email from Record (Module Context)
     */
    public function composeFromRecord($module, $recordId, Request $request)
    {
        $orgId = auth()->user()->organization_id;
        if (!$orgId) return $this->error('Organization not found');

        $values = $request->input('data.values');
        if (!$values) {
            $values = $request->all(); 
        }

        $validator = Validator::make($values, [
            'server_id' => 'required|uuid',
            'recipients' => 'required|array|min:1',
            'subject' => 'nullable|string',
            'body' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return $this->error(collect($validator->errors()->all())->implode(','));
        }

        try {
            // Note: URL module/recordId provided context, but payload defines specific recipients.
            // We pass the payload values to the service. The service uses the payload's module/record_id.
            $results = $this->mailboxService->processAndSendComplexRecipients($values, $orgId, auth()->id());
            return $this->success($results, 'Emails processed');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

}
