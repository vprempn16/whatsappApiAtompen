<?php

namespace App\Services\Mail;

use App\Modules\Api\V1\Mail\Models\MailLog;
use App\Modules\Api\V1\Mail\Models\MailServer;
use App\Modules\Api\V1\Mailbox\Models\MailboxFolder;
use App\Modules\Api\V1\Mailbox\Models\MailLabel;
use App\Modules\Api\V1\Mailbox\Models\MailDraft;
use App\Modules\Api\V1\Mailbox\Models\MailSignature;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use App\Mail\GenericMail;

class MailboxService
{
    protected $mailService;

    public function __construct(MailService $mailService)
    {
        $this->mailService = $mailService;
    }

    /**
     * Get Unified Inbox
     */
     public function listFolders(string $orgId,string $mailServerId)
    {
        return MailboxFolder::where('organization_id', $orgId)
            ->where('deleted', 0)
            ->where('mail_server_id', $mailServerId)
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Create folder
     */

    public function createFolder(string $orgId, string $userId, array $data)
    {
        return MailboxFolder::create([
            'organization_id' => $orgId,
            'created_by' => $userId,
            'user_id' => $userId,
            'mail_server_id' => $data['mail_server_id'] ?? null,
            'name' => $data['name'],
            'slug' => Str::slug($data['name']) . '-' . Str::random(4),
            'type' => 'custom',
            'icon' => $data['icon'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
            'is_default' => 0,
            'deleted' => 0,
            'created_at' => now()
        ]);
    }

    /**
     * Update folder
     */
    public function updateFolder(string $id, string $orgId, array $data)
    {
        $folder = MailboxFolder::where('id', $id)
            ->where('organization_id', $orgId)
            ->where('deleted', 0)
            ->firstOrFail();

        $folder->update([
            'name' => $data['name'] ?? $folder->name,
            'sort_order' => $data['sort_order'] ?? $folder->sort_order,
            'icon' => $data['icon'] ?? $folder->icon
        ]);

        return $folder;
    }

    
    /**
     * Delete folder (soft)
     */
    public function deleteFolder(string $id, string $orgId)
    {
        $folder = MailboxFolder::where('id', $id)
            ->where('organization_id', $orgId)
            ->where('deleted', 0)
            ->firstOrFail();

        // Optional: move mails back to inbox
        MailLog::where('folder_id', $folder->id)
            ->update(['folder_id' => null]);

        $folder->update(['deleted' => 1]);

        return true;
    }

     public function listLabels(string $orgId, ?string $mailServerId = null)
    {
        $query = MailLabel::where('organization_id', $orgId)
            ->where('deleted', 0);

        if ($mailServerId) {
            $query->where('mail_server_id', $mailServerId);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    /**
     * Create label
     */
    public function createLabel(string $orgId, string $userId, array $data)
    {
        return MailLabel::create([
            'organization_id' => $orgId,
            'created_by' => $userId,
            'user_id' => $userId,
            'mail_server_id' => $data['mail_server_id'] ?? null,

            'name' => $data['name'],
            'slug' => Str::slug($data['name']) . '-' . Str::random(4),

            'color' => $data['color'] ?? '#3B82F6',
            'description' => $data['description'] ?? null,
            'deleted' => 0,
            'created_at' => now()
        ]);
    }

    /**
     * Update label
     */
    public function updateLabel(string $id, string $orgId, array $data)
    {
        $label = MailLabel::where('id', $id)
            ->where('organization_id', $orgId)
            ->where('deleted', 0)
            ->firstOrFail();

        $label->update([
            'name' => $data['name'] ?? $label->name,
            'color' => $data['color'] ?? $label->color,
            'description' => $data['description'] ?? $label->description
        ]);

        return $label;
    }

    /**
     * Delete label (soft)
     */
    public function deleteLabel(string $id, string $orgId)
    {
        $label = MailLabel::where('id', $id)
            ->where('organization_id', $orgId)
            ->where('deleted', 0)
            ->firstOrFail();

        // Remove mapping from emails
        DB::table('mail_email_labels')
            ->where('label_id', $label->id)
            ->delete();

        $label->update(['deleted' => 1]);

        return true;
    }
    public function getInbox(string $orgId, string $userId, array $filters = [], int $perPage = 20)
    {
        $query = MailLog::with(['folder', 'labels', 'attachments'])
            ->where('organization_id', $orgId)
            ->where('deleted', 0);

        // Filter by specific mail server if requested
        if (!empty($filters['mail_server_id'])) {
            $query->where('mail_server_id', $filters['mail_server_id']);
        }

        // Folder logic
        if (!empty($filters['folder_id'])) {
            // Check if it's a system folder slug or uuid
            $folder = MailboxFolder::where('id', $filters['folder_id'])
                ->orWhere(function($q) use ($filters, $orgId) {
                    $q->where('slug', $filters['folder_id'])
                      ->where('organization_id', $orgId);
                })->first();

            if ($folder) {
                $query->where('folder_id', $folder->id);
            } else {
                // If special slugs not in DB yet (or virtual)
                $this->applyVirtualFolderFilter($query, $filters['folder_id']);
            }
        } else {
            // Default to Inbox (mapped folder or virtual)
            $this->applyVirtualFolderFilter($query, 'inbox');
        }

        // Other filters
        if (isset($filters['is_read'])) {
            $query->where('is_read', $filters['is_read']);
        }
        
        if (isset($filters['is_starred'])) {
            $query->where('is_starred', $filters['is_starred']);
        }

        // Search
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function($q) use ($search) {
                $q->where('subject', 'like', "%{$search}%")
                  ->orWhere('to_email', 'like', "%{$search}%")
                  ->orWhere('from_email', 'like', "%{$search}%")
                  ->orWhere('body', 'like', "%{$search}%");
            });
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    private function applyVirtualFolderFilter($query, $slug)
    {
        switch ($slug) {
            case 'inbox':
                $query->where('direction', 'incoming')
                      ->whereNull('trashed_at')
                      ->whereNull('archived_at')
                      ->whereNull('folder_id'); // If not manually moved
                break;
            case 'sent':
                $query->where('direction', 'outgoing')
                      ->whereNull('trashed_at');
                break;
            case 'trash':
                $query->whereNotNull('trashed_at');
                break;
            case 'archive':
                $query->whereNotNull('archived_at');
                break;
            case 'starred':
                $query->where('is_starred', 1);
                break;
            case 'all':
                $query->whereNull('trashed_at');
                break;
        }
    }

    /**
     * Get Single Email
     */
    public function getEmail(string $id, string $orgId)
    {
        $email = MailLog::with(['folder', 'labels', 'attachments'])
            ->where('id', $id)
            ->where('organization_id', $orgId)
            ->where('deleted', 0)
            ->firstOrFail();
            
        // Mark as read if not already
        if (!$email->is_read) {
            $email->update(['is_read' => 1]);
        }

        return $email;
    }

    
    /**
     * Bulk Actions
     */
    public function bulkAction(array $ids, string $action, string $orgId, array $params = [])
    {
        $query = MailLog::whereIn('id', $ids)->where('organization_id', $orgId);
        
        switch ($action) {
            case 'delete': // Move to trash
                $query->update(['trashed_at' => now(), 'folder_id' => null]);
                break;
            case 'archive':
                $query->update(['archived_at' => now(), 'folder_id' => null]);
                break;
            case 'restore':
                $query->update(['trashed_at' => null, 'archived_at' => null]);
                break;
            case 'star':
                $query->update(['is_starred' => 1]);
                break;
            case 'unstar':
                $query->update(['is_starred' => 0]);
                break;
            case 'read':
                $query->update(['is_read' => 1]);
                break;
            case 'unread':
                $query->update(['is_read' => 0]);
                break;
            case 'move':
                if (isset($params['folder_id'])) {
                    $query->update([
                        'folder_id' => $params['folder_id'],
                        'trashed_at' => null,
                        'archived_at' => null
                    ]);
                }
                break;
            case 'permanent_delete':
                $query->update(['deleted' => 1]);
                break;
        }

        return true;
    }

    // --- Drafts ---

    public function listDrafts(string $orgId, string $userId, ?string $mailServerId = null)
    {
        $query = MailDraft::where('organization_id', $orgId)
            ->where('user_id', $userId)
            ->where('deleted', 0);

        if ($mailServerId) {
            $query->where('mail_server_id', $mailServerId);
        }

        return $query->orderBy('updated_at', 'desc')->get();
    }

    public function getDraft(string $id, string $orgId)
    {
        return MailDraft::where('id', $id)
            ->where('organization_id', $orgId)
            ->where('deleted', 0)
            ->firstOrFail();
    }

    public function createDraft(string $orgId, string $userId, array $data)
    {
        return MailDraft::create([
            'organization_id' => $orgId,
            'user_id' => $userId,
            'mail_server_id' => $data['mail_server_id'] ?? null,
            'to' => $data['to'] ?? [],
            'cc' => $data['cc'] ?? [],
            'bcc' => $data['bcc'] ?? [],
            'subject' => $data['subject'] ?? null,
            'body' => $data['body'] ?? null,
            'reply_to_mail_log_id' => $data['reply_to_mail_log_id'] ?? null,
            'forward_from_mail_log_id' => $data['forward_from_mail_log_id'] ?? null,
            'related_module' => $data['related_module'] ?? null,
            'related_record_id' => $data['related_record_id'] ?? null,
            'deleted' => 0
        ]);
    }

    public function updateDraft(string $id, string $orgId, array $data)
    {
        $draft = $this->getDraft($id, $orgId);
        $draft->update($data);
        return $draft;
    }

    public function deleteDraft(string $id, string $orgId)
    {
        $draft = $this->getDraft($id, $orgId);
        $draft->update(['deleted' => 1]);
        return true;
    }

    // --- Signatures ---

    public function listSignatures(string $orgId, string $userId, ?string $mailServerId = null)
    {
        $query = MailSignature::where('organization_id', $orgId)
            ->where('user_id', $userId)
            ->where('deleted', 0);

        if ($mailServerId) {
            $query->where('mail_server_id', $mailServerId);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    public function createSignature(string $orgId, string $userId, array $data)
    {
        if (!empty($data['is_default'])) {
            $this->clearDefaultSignature($orgId, $userId);
        }

        return MailSignature::create([
            'organization_id' => $orgId,
            'user_id' => $userId,
            'mail_server_id' => $data['mail_server_id'] ?? null,
            'name' => $data['name'],
            'content' => $data['content'],
            'is_default' => $data['is_default'] ?? 0,
            'deleted' => 0
        ]);
    }

    public function updateSignature(string $id, string $orgId, string $userId, array $data)
    {
        $signature = MailSignature::where('id', $id)
            ->where('organization_id', $orgId)
            ->where('deleted', 0)
            ->firstOrFail();

        if (!empty($data['is_default']) && $data['is_default'] == 1) {
            $this->clearDefaultSignature($orgId, $userId, $id);
        }

        $signature->update($data);

        return $signature;
    }

    public function deleteSignature(string $id, string $orgId)
    {
        $signature = MailSignature::where('id', $id)
            ->where('organization_id', $orgId)
            ->firstOrFail();
            
        $signature->update(['deleted' => 1]);
        
        return true;
    }

    private function clearDefaultSignature($orgId, $userId, $excludeId = null)
    {
        $query = MailSignature::where('organization_id', $orgId)
            ->where('user_id', $userId);
            
        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        $query->update(['is_default' => 0]);
    }

    // --- Recipient Resolution ---

    public function resolveRecipients(array $recipients, string $module, string $recordId, string $orgId)
    {
        $resolvedEmails = [];
        $errors = [];

         // Fetch Record
        $modelClass = "App\\Modules\\Api\\V1\\" . ucfirst($module) . "\\Models\\" . ucfirst($module);
        if (!class_exists($modelClass)) {
             $modelClass = "App\\Models\\" . ucfirst($module);
        }

        if (!class_exists($modelClass)) {
            return ['emails' => [], 'errors' => ["Module {$module} not found"]];
        }

        $record = $modelClass::where('id', $recordId)->where('organization_id', $orgId)->first();
        if (!$record) {
             return ['emails' => [], 'errors' => ["Record not found"]];
        }

        foreach ($recipients as $recipientItem) {
            $to = null;
            $fieldName = $recipientItem;

            // 1. CRM Field Lookup
            $crmField = DB::table('crm_fields')
                ->where('modulename', $module)
                ->where('apifieldname', $recipientItem)
                ->first('fieldname');

            if ($crmField) {
                 $realFieldName = $crmField->fieldname;
                 $to = $record->{$realFieldName} ?? null;
                 $fieldName = $realFieldName;
                 
                  if (!$to) {
                     $errors[] = "No email address found in field '{$recipientItem}' ($fieldName)";
                     continue;
                 }

            } else {
                 if (filter_var($recipientItem, FILTER_VALIDATE_EMAIL)) {
                     $to = $recipientItem;
                 } else {
                     $errors[] = "Field '{$recipientItem}' not found in module '{$module}' and is not a valid email";
                     continue;
                 }
            }
            
            // Validate Resolved Email
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Invalid email format resolution: '$to' for item '$recipientItem'";
                continue;
            }

            $resolvedEmails[] = $to;
        }

        return ['emails' => array_unique($resolvedEmails), 'errors' => $errors];
    }

    // --- Sent Controller Helpers ---

    public function getSentMails(string $orgId, string $userId, array $filters = [], int $perPage = 20)
    {
         // Force outgoing filters
         $filters['folder_id'] = 'sent';
         return $this->getInbox($orgId, $userId, $filters, $perPage);
    }

    public function getSentMail(string $id, string $orgId)
    {
        return MailLog::with(['folder', 'labels', 'attachments'])
            ->where('id', $id)
            ->where('organization_id', $orgId)
            ->where('direction', 'outgoing')
            ->where('deleted', 0)
            ->firstOrFail();
    }

    // --- Complex Recipient Processing & Sending ---

 

    public function processAndSendComplexRecipients(array $data, string $orgId, string $userId)
    {
	    $recipients = $data['recipients'] ?? [];
	    $results = [];

	    foreach ($recipients as $item) {
		    $to = null;
		    $fieldName = $item['field'] ?? 'Unknown Field';
		    // Handle typos/variations as per requirement
		    $module = $item['module_nam'] ?? $item['module_name'] ?? null; 
		    $recordId = $item['recotdI'] ?? $item['record_id'] ?? $item['recordId'] ?? null; 

		    $error = null;

		    // 1. Validation of Context
		    if (!$module || !$recordId) {
			    $error = "Missing module or record ID";
		    } else {
			    // 2. Resolve
			    $modelClass = "App\\Modules\\Api\\V1\\" . ucfirst($module) . "\\Models\\" . ucfirst($module);
			    if (!class_exists($modelClass)) {
				    $modelClass = "App\\Models\\" . ucfirst($module);
			    }

			    if (class_exists($modelClass)) {
				    $record = $modelClass::find($recordId);
				    // The user snippet uses direct fetch.
				    if ($record) {
					    // Check Org Access if model has checking? 
					    // Assuming standard multitenant
					    if (isset($record->organization_id) && $record->organization_id != $orgId) {
						    $error = "Record access denied";
					    } else {
						    // Field lookup
						    $fieldVal = $item['field'] ?? null;
						    if ($fieldVal) {
							    $crmField = DB::table('crm_fields')
								    ->where('modulename', $module)
								    ->where('apifieldname', $fieldVal)
								    ->first('fieldname');

							    $realFieldName = $crmField ? $crmField->fieldname : $fieldVal;

							    $to = $record->{$realFieldName} ?? null;

							    if (!$to) {
								    $error = "No email address found in field '$fieldVal'";
							    } elseif (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
								    $error = "Invalid email format: '$to'";
							    }
						    } else {
							    $error = "Field name not provided";
						    }
					    }
				    } else {
					    $error = "Record not found";
				    }
			    } else {
				    $error = "Module $module not found";
			    }
		    }

		    // Check if we should log failure
		    if ($error) {
			    MailLog::create([
				    'organization_id' => $orgId,
				    'mail_server_id' => $data['server_id'] ?? null,
				    'direction' => 'outgoing',
				    'to_email' => $to ?? 'Unknown',
				    'from_email' => 'System', // Placeholder
				    'subject' => $data['subject'] ?? 'No Subject',
				    'body' => $data['body'] ?? '',
				    'status' => 'failed',
				    'error_message' => $error,
				    'created_by' => $userId,
				    'info' => ['recipient_item' => $item]
			    ]);

			    $results[] = [
				    'item' => $item,
				    'success' => false,
				    'error' => $error
			    ];
			    continue;
		    }

		    // Success -> Send
		    $mailData = [
			    'server_id' => $data['server_id'],
			    'to' => $to, // String
			    'subject' => $data['subject'] ?? 'No Subject',
			    'body' => $data['body'] ?? '',
			    'cc' => $data['cc'] ?? [],
			    'bcc' => $data['bcc'] ?? [],
			    'folder_id' => $data['folder_id'] ?? null
		    ];

		    if (isset($data['attachments'])) {
			    $mailData['attachments'] = $data['attachments'];
		    }

		    // We pass module/recordId for relation
		    // Pass the original field name to sendMail for logging and formatting
		    $mailData['related_field'] = $item['field'] ?? null;

		    try {
			    $result = $this->mailService->sendMail($mailData, $module, $recordId);

			    // Response formatting as requested
			    if ($result['status'] === true && isset($result['data'])) {
				    // Flatten structure: "response" key contains the formatted data from sendMail now
				    $results[] = [
					    'item' => $item,
					    'email' => $to,
					    'success' => true,
					    'response' => $result['data'] 
				    ];
			    } else {
				    $results[] = [
					    'item' => $item,
					    'email' => $to,
					    'success' => false,
					    'error' => $result['error'] ?? 'Unknown Error'
				    ];
			    }

		    } catch (\Exception $e) {
			    $results[] = [
				    'item' => $item,
				    'success' => false,
				    'error' => $e->getMessage()
			    ];
		    }
	    }
	    return $results;
    }
    public function syncMailboxStructure(string $serverId, string $orgId, string $userId, string $type = 'Folder')
    {
    $imapFolders = $this->mailService->getImapFolders($serverId, $orgId);
    $synced = [];

    // -------------------------------------------------------------
    // 1. SYNC FOLDERS
    // -------------------------------------------------------------
    if (strtolower($type) === 'folder') {

        foreach ($imapFolders as $imapFolder) {

            $name = $imapFolder['name'];
            $path = $imapFolder['path'] ?? '';

            // Determine Type & Icon
            $folderType = 'user';
            $icon = 'folder';
            $isSystem = false;

            // -------------------------------
            // Gmail/System Folder Detection
            // -------------------------------
            if (stripos($name, 'inbox') !== false || stripos($path, 'INBOX') !== false) {
                $folderType = 'system';
                $icon = 'inbox';
                $isSystem = true;

            } elseif (stripos($name, 'sent') !== false || stripos($path, 'Sent') !== false) {
                $folderType = 'system';
                $icon = 'send';
                $isSystem = true;

            } elseif (
                stripos($name, 'trash') !== false ||
                stripos($name, 'bin') !== false ||
                stripos($path, 'Bin') !== false ||
                stripos($path, 'Trash') !== false
            ) {
                $folderType = 'system';
                $icon = 'trash';
                $isSystem = true;

            } elseif (stripos($name, 'draft') !== false || stripos($path, 'Drafts') !== false) {
                $folderType = 'system';
                $icon = 'file-text';
                $isSystem = true;

            } elseif (
                stripos($name, 'junk') !== false ||
                stripos($name, 'spam') !== false ||
                stripos($path, 'Spam') !== false
            ) {
                $folderType = 'system';
                $icon = 'alert-octagon';
                $isSystem = true;
            }

            $slug = Str::slug($name);

            $folder = MailboxFolder::updateOrCreate(
                [
                    'organization_id' => $orgId,
                    'mail_server_id' => $serverId,
                    'name' => $name
                ],
                [
                    'user_id' => $userId,
                    'slug' => $slug,
                    'type' => $folderType,
                    'icon' => $icon,
                    'deleted' => 0
                ]
            );

            $synced[] = $folder;

            // ---------------------------------------------------------
            // Map existing logs to folders (only if folder_id empty)
            // ---------------------------------------------------------
            if ($isSystem) {

                // INBOX
                if (stripos($name, 'inbox') !== false || stripos($path, 'INBOX') !== false) {

                    MailLog::where('organization_id', $orgId)
                        ->where('mail_server_id', $serverId)
                        ->where('direction', 'incoming')
                        ->where(function ($q) {
                            $q->whereNull('folder_id')
                              ->orWhere('folder_id', '');
                              
                        })
                        ->update(['folder_id' => $folder->id]);

                }

                // SENT
                if (stripos($name, 'sent') !== false || stripos($path, 'Sent') !== false) {

                    MailLog::where('organization_id', $orgId)
                        ->where('mail_server_id', $serverId)
                        ->where('direction', 'outgoing')
                        ->where(function ($q) {
                            $q->whereNull('folder_id')
                              ->orWhere('folder_id', '');
                              
                        })
                        ->update(['folder_id' => $folder->id]);

                }

                // DRAFTS
                if (stripos($name, 'draft') !== false || stripos($path, 'Drafts') !== false) {

                    MailDraft::where('organization_id', $orgId)
                        ->where('mail_server_id', $serverId)
                        ->where(function ($q) {
                            $q->whereNull('folder_id')
                              ->orWhere('folder_id', '');
                             
                        })
                        ->update(['folder_id' => $folder->id]);

                }
            }
        }
    }

    // -------------------------------------------------------------
    // 2. SYNC LABELS
    // -------------------------------------------------------------
    if (strtolower($type) === 'label') {

        foreach ($imapFolders as $imapFolder) {

            $name = $imapFolder['name'];
            $path = $imapFolder['path'] ?? '';

            // Skip system folders
            if (stripos($name, 'inbox') !== false || stripos($path, 'INBOX') !== false) continue;
            if (stripos($name, 'sent') !== false || stripos($path, 'Sent') !== false) continue;
            if (stripos($name, 'trash') !== false || stripos($path, 'Trash') !== false) continue;
            if (stripos($name, 'bin') !== false || stripos($path, 'Bin') !== false) continue;
            if (stripos($name, 'draft') !== false || stripos($path, 'Drafts') !== false) continue;
            if (stripos($name, 'junk') !== false) continue;
            if (stripos($name, 'spam') !== false || stripos($path, 'Spam') !== false) continue;

            $slug = Str::slug($name);

            $label = MailLabel::updateOrCreate(
                [
                    'organization_id' => $orgId,
                    'mail_server_id' => $serverId,
                    'name' => $name
                ],
                [
                    'user_id' => $userId,
                    'slug' => $slug,
                    'color' => '#6B7280',
                    'deleted' => 0
                ]
            );

            $synced[] = $label;
        }
    }

    return $synced;
}

}
