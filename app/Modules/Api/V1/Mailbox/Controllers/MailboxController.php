<?php

namespace App\Modules\Api\V1\Mailbox\Controllers;

use App\Modules\Api\V1\Mail\Models\MailImapServer;
use App\Modules\Api\V1\Mail\Models\MailLog;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\Mail\MailboxService;
use App\Services\Mail\MailService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\ApiController;

class MailboxController extends ApiController
{
    protected $mailboxService;
    protected $mailService;

    public function __construct(MailboxService $mailboxService, MailService $mailService)
    {
        $this->mailboxService = $mailboxService;
        $this->mailService = $mailService;
    }

    /**
     * Sync All Folders for a server
     */
    public function syncAll(Request $request, $mailServerId)
    {
        $orgId = auth()->user()->organization_id;
        if (!$orgId) return $this->error('Organization not found');

        try {
            $limit = $request->get('limit', 20);
            
            // If folder_id is passed in data/query, we might want to branch, 
            // but user specifically asked for separation.

            // 2. Sync all folders/mails
            $results = $this->mailService->syncAllFolders($mailServerId, $limit, $orgId);

            return $this->success($results, 'All folders synced successfully');
        } catch (\Throwable $e) {
            return $this->error('Failed to sync all folders: ' . $e->getMessage());
        }
    }

    /**
     * Sync Specific Folder
     */
    public function syncFolder(Request $request, $mailServerId, $folderId)
    {
        $orgId = auth()->user()->organization_id;
        if (!$orgId) return $this->error('Organization not found');

        try {
            $limit = $request->get('limit', 20);

            // Get folder details via MailboxService
            $folder = $this->mailboxService->getFolderDefination($folderId, $orgId);
            if (!$folder) {
                return $this->error('Folder not found');
            }

            $results = $this->mailService->fetchImapInbox($mailServerId, $limit, $orgId, $folder);

            return $this->success($results, "Folder '{$folder->name}' synced successfully");
        } catch (\Throwable $e) {
            return $this->error('Failed to sync folder: ' . $e->getMessage());
        }
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
            return $this->errorFromException($e, 'Failed to fetch inbox');
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

        $validator = Validator::make(['id' => $id], [
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
        } catch (\Throwable $e) {
            return $this->errorFromException($e, 'Failed to fetch email');
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

        // Ensure recipients are available if 'to' was mapped
        if (!isset($data['recipients']) && isset($values['recipients'])) {
            $data['recipients'] = $values['recipients'];
        }

        // Handle File Uploads
        // Standard multipart/form-data handling for 'attachments[]'
        $attachments = [];
        if ($request->hasFile('attachments')) {
            $folderPath = 'mail-attachments/' . $orgId;
            if (!\Illuminate\Support\Facades\Storage::disk('mail_attachments')->exists($folderPath)) {
                \Illuminate\Support\Facades\Storage::disk('mail_attachments')->makeDirectory($folderPath);
            }

            foreach ($request->file('attachments') as $file) {
                 $path = $file->store($folderPath, 'mail_attachments');
                 $attachments[] = [
                     'path' => \Illuminate\Support\Facades\Storage::disk('mail_attachments')->path($path),
                     'name' => $file->getClientOriginalName(),
                     'original_name' => $file->getClientOriginalName(),
                     'mime_type' => $file->getMimeType(),
                     'size' => $file->getSize(),
                     'disk' => 'mail_attachments'
                 ];
            }
        }
        $data['attachments'] = $attachments;

        // Check for complex recipients (Arrays with module/recordId)
        $isComplex = false;
        if (isset($data['recipients']) && is_array($data['recipients']) && !empty($data['recipients'])) {
             $first = reset($data['recipients']);
             // If the first item is an array and looks like a complex recipient
             if (is_array($first) && (isset($first['module_name']) || isset($first['recordId']) || isset($first['record_id']))) {
                 $isComplex = true;
             }
        }
        
        \Log::info('MailboxController::send', [
            'isComplex' => $isComplex,
            'recipients_count' => count($data['recipients'] ?? []),
            'attachments_count' => count($attachments),
            'data_attachments_set' => isset($data['attachments'])
        ]);

        // Branch Logic
        if ($isComplex) {
             try {
                $results = $this->mailboxService->processAndSendComplexRecipients($data, $orgId, auth()->id());
                return $this->success($results, 'Emails processed');
             } catch (\Throwable $e) {
                 \Log::error('Complex Send Failed', ['error' => $e->getMessage()]);
                 return $this->error('Failed to process recipients: ' . $e->getMessage());
             }
        }

        // Use existing MailService logic for standard emails
        try {
            $result = $this->mailService->sendMail($data);
            
            if ($result['status'] === false) {
                 return $this->error('Failed to send email');
            }
            
            return $this->success($result['data'], 'Email sent successfully');

        } catch (\Throwable $e) {
            return $this->errorFromException($e, 'Failed to send email');
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
            'params.folder_id' => 'required_if:action,move|uuid',
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
            return $this->errorFromException($e, 'Failed to perform bulk action');
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

        // Handle File Uploads (Same logic as send method)
        $attachments = [];
        if ($request->hasFile('attachments')) {
            $folderPath = 'mail-attachments/' . $orgId;
            if (!\Illuminate\Support\Facades\Storage::disk('mail_attachments')->exists($folderPath)) {
                \Illuminate\Support\Facades\Storage::disk('mail_attachments')->makeDirectory($folderPath);
            }

            foreach ($request->file('attachments') as $file) {
                 $path = $file->store($folderPath, 'mail_attachments');
                 $attachments[] = [
                     'path' => \Illuminate\Support\Facades\Storage::disk('mail_attachments')->path($path),
                     'name' => $file->getClientOriginalName(),
                     'original_name' => $file->getClientOriginalName(),
                     'mime_type' => $file->getMimeType(),
                     'size' => $file->getSize(),
                     'disk' => 'mail_attachments'
                 ];
            }
        }
        $values['attachments'] = $attachments;

        \Log::info('MailboxController::composeFromRecord', [
            'recipients_count' => count($values['recipients'] ?? []),
            'attachments_count' => count($attachments),
            'data_attachments_set' => !empty($values['attachments'])
        ]);

        try {
            // Note: URL module/recordId provided context, but payload defines specific recipients.
            // We pass the payload values to the service. The service uses the payload's module/record_id.
            $results = $this->mailboxService->processAndSendComplexRecipients($values, $orgId, auth()->id());
            
            return $this->success($results, 'Emails processed');
        } catch (\Throwable $e) {
            return $this->errorFromException($e, 'Failed to process emails');
        }
    }

    public function getImapServers(Request $request)
    {
        $orgId = $request->header('X-Organization-ID') ?? auth()->user()->organization_id;
        try {
            $servers = MailImapServer::where('organization_id', $orgId)
                ->where('deleted', 0)
                ->where('is_active', 1)
                ->get();
            return $this->success($servers, 'Servers retrieved successfully');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function getFolders(Request $request, $mailServerId)
    {
        $orgId = $request->header('X-Organization-ID') ?? auth()->user()->organization_id;
        try {
            $folders = $this->mailboxService->listFolders($orgId, $mailServerId);
            return $this->success($folders, 'Folders retrieved successfully');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function getEmailsInFolder(Request $request, $mailServerId, $folderIdentifier)
    {
        $orgId = $request->header('X-Organization-ID') ?? auth()->user()->organization_id;
        $userId = auth()->id();
        $perPage = $request->input('per_page', 20);

        $filters = $request->all();
        $filters['mail_server_id'] = $mailServerId;
        $filters['folder_id'] = $folderIdentifier;

        try {
            $emails = $this->mailboxService->getInbox($orgId, $userId, $filters, $perPage);
            return $this->success($emails, 'Emails retrieved successfully');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function getAllEmails(Request $request, $mailServerId)
    {
        $orgId = $request->header('X-Organization-ID') ?? auth()->user()->organization_id;
        $userId = auth()->id();
        $perPage = $request->input('per_page', 20);

        $filters = $request->all();
        $filters['mail_server_id'] = $mailServerId;
        $filters['folder_id'] = 'all';

        try {
            $emails = $this->mailboxService->getInbox($orgId, $userId, $filters, $perPage);
            return $this->success($emails, 'Emails retrieved successfully');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }
}
