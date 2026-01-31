<?php 
namespace App\Services\Mail;

use App\Modules\Api\V1\Mail\Models\MailServer;
use App\Modules\Api\V1\Mail\Models\MailLog;
use App\Modules\Api\V1\Mail\Models\MailImapServer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use App\Mail\GenericMail;

class MailService{

	public function saveServer(array $data)
	{
		$orgId = $data['organization_id'];
		$username = $data['username'];

		// Validate SMTP credentials before saving
		if (!empty($data['host']) && !empty($data['port']) && !empty($data['username']) && !empty($data['password'])) {
			$validationResult = $this->validateSmtpCredentials(
				$data['host'],
				$data['port'],
				$data['username'],
				$data['password'],
				$data['encryption'] ?? 'tls',
				$data['from_email'] ?? $data['username']
			);

			if (!$validationResult['valid']) {
				throw new \Exception($validationResult['error']);
			}
		}

		if (!empty($data['password'])) {
			$data['password'] = Crypt::encryptString($data['password']);
		}

		$server = MailServer::where('organization_id', $orgId)
			->where('username', $username)
			->where('deleted', 0)
			->first();

		if ($server) {
			// Update existing
			$server->update([
				...$data,
				'updated_at' => now(),
			]);

			return $server;
		}

		// Create new
		return MailServer::create([
			...$data,
			'id' => (string) Str::uuid(),
			'created_at' => now(),
			'deleted' => 0,
		]);
	}

	public function deleteServer($serverId, $orgId)
	{
		$server = MailServer::where('id', $serverId)
			->where('organization_id', $orgId)
			->where('deleted', 0)
			->first();

		if (!$server) {
			return false;
		}

		$server->update([
			'deleted' => 1,
			'updated_at' => now()
		]);

		return true;
	}

	public function setOutgoingServer($serverId, $orgId)
	{
		// Disable all smtp for this org
		MailServer::where('organization_id', $orgId)
			->where('mail_type', 'smtp')
			->where('deleted', 0)
			->update(['is_active' => 0]);

		// Enable selected server
		return MailServer::where('id', $serverId)
			->where('organization_id', $orgId)
			->where('deleted', 0)
			->update([
				'is_active' => 1,
				'updated_at' => now()
			]);
	}

	public function connectServer($serverId, $orgId)
	{
		$server = MailServer::where('id', $serverId)->where('organization_id', $orgId)->where('deleted', 0)->first();

		if (!$server) {
			return ['status' => 'failed', 'error' => 'Server not found'];
		}

		try {
			$this->applySmtpConfig($server);

			Mail::raw('SMTP Test Connection', function ($msg) use ($server) {
				$msg->to($server->from_email)
	->subject('SMTP Connection Test');
			});

			return ['status' => 'success'];
		} catch (\Exception $e) {
			return ['status' => 'failed', 'error' => $e->getMessage()];
		}
	}

	private function validateSmtpCredentials($host, $port, $username, $password, $encryption, $fromEmail)
	{
		try {
			// Temporarily configure SMTP settings
			Config::set('mail.mailers.validation', [
				'transport' => 'smtp',
				'host' => $host,
				'port' => $port,
				'encryption' => $encryption,
				'username' => $username,
				'password' => $password,
			]);

			Config::set('mail.default', 'validation');
			Config::set('mail.from.address', $fromEmail);
			Config::set('mail.from.name', 'Validation Test');

			// Attempt to send a test email
			Mail::raw('SMTP Validation Test', function ($msg) use ($fromEmail) {
				$msg->to($fromEmail)
					->subject('SMTP Validation Test');
			});

			return ['valid' => true];
		} catch (\Exception $e) {
			return [
				'valid' => false,
				'error' => 'SMTP validation failed: ' . $e->getMessage()
			];
		}
	}

	public function sendMail(array $data, $module = null, $recordId = null)
	{
		$orgId = auth()->user()->organization_id;
		$userId = auth()->id();

		try {
			$server = MailServer::where('id', $data['server_id'])
				->where('organization_id', $orgId)
				->where('deleted', 0)
				->firstOrFail();

			$password = Crypt::decryptString($server->password);

			// Dynamic SMTP config
			Config::set('mail.mailers.dynamic', [
				'transport' => 'smtp',
				'host' => $server->host,
				'port' => $server->port,
				'encryption' => $server->encryption,
				'username' => $server->username,
				'password' => $password,
			]);

			Config::set('mail.default', 'dynamic');

			// Ensure FROM is never empty
			$fromAddress = $server->from_address ?: $server->username;
			$fromName = $server->from_name ?: 'System';

			Config::set('mail.from.address', $fromAddress);
			Config::set('mail.from.name', $fromName);

			// Generate Tracking Token
			$trackingToken = (string) Str::uuid();
			$trackingUrl = config('app.url') . "/api/v1/mail/track/" . $trackingToken;
			$pixelHtml = '<img src="' . $trackingUrl . '" width="1" height="1" style="display:none !important;" />';
            \Log::info("send mail log", ['url' => $trackingUrl, 'trackingToken' => $trackingToken]);
			// Inject pixel into body

           
			$bodyWithPixel = $data['body'] . $pixelHtml;

			// Send mail
			Mail::to($data['to'])->send(
				new GenericMail($data['subject'], $bodyWithPixel)
			);

			// Log success
			$mailLog = $this->createLog(
				$orgId,
				$server->id,
				$userId,
				'outgoing',
				$data['to'],
				$fromAddress,
				$data['subject'],
				'success',
				null,
				[
        		'cc' => $data['cc'] ?? [],
        		'bcc' => $data['bcc'] ?? [],
        		'mailer' => 'smtp'
    			],
				$bodyWithPixel,
				null,
				0,
				null,
				null,
				null,
				null,
				$trackingToken
			);

			// Create mail relation if module and recordId provided
			if ($module && $recordId) {
				\App\Modules\Api\V1\Mail\Models\MailRelation::create([
					'id' => (string) Str::uuid(),
					'organization_id' => $orgId,
					'module' => $module,
					'record_id' => $recordId,
					'mail_log_id' => $mailLog->id,
					'created_by' => $userId,
					'created_at' => now(),
					'deleted' => 0
				]);
			}

			return [
				'status' => true,
				'data' => [
					'organization_id' => $mailLog->organization_id,
					'mail_server_id' => $mailLog->mail_server_id,
					'created_by' => $mailLog->created_by,
					'direction' => $mailLog->direction,
					'to_email' => $mailLog->to_email,
					'from_email' => $mailLog->from_email,
					'subject' => $mailLog->subject,
					'tracking_token' => $mailLog->tracking_token,
					'status' => $mailLog->status
				]
			];

		} catch (\Throwable $e) {

			// Log failure
			$this->createLog(
				$orgId,
				$data['server_id'] ?? null,
				$userId,
				'outgoing',
				$data['to'] ?? null,
				null,
				$data['subject'] ?? null,
				'failed',
				$e->getMessage(),
				[]
			);

			return ['status' => false, 'error' => $e->getMessage()];
		}
	}
	private function applySmtpConfig($server)
	{
		config([
			'mail.mailers.smtp.host' => $server->host,
			'mail.mailers.smtp.port' => $server->port,
			'mail.mailers.smtp.username' => $server->username,
			'mail.mailers.smtp.password' => $server->password,
			'mail.mailers.smtp.encryption' => $server->encryption,
			'mail.from.address' => $server->from_email,
			'mail.from.name' => $server->from_name,
		]);
	}
	public function saveImapServer(array $data)
{
    // check SMTP server exists for this org
    $exists = MailServer::where('id', $data['mail_server_id'])
        ->where('organization_id', $data['organization_id'])
        ->where('deleted', 0)
        ->exists();

    if (!$exists) {
        throw new \Exception('Invalid mail server');
    }

    // encrypt password
    if (!empty($data['password'])) {
        $data['password'] = Crypt::encryptString($data['password']);
    }

    // update if already exists for this server
    $imap = MailImapServer::where('organization_id', $data['organization_id'])
        ->where('mail_server_id', $data['mail_server_id'])
        ->where('deleted', 0)
        ->first();

    if ($imap) {
        $imap->update([
            'host'       => $data['host'],
            'port'       => $data['port'],
            'encryption' => $data['encryption'],
            'username'   => $data['username'],
            'password'   => $data['password'] ?? $imap->password,
            'folder'     => $data['folder'] ?? 'INBOX',
            'updated_at' => now(),
        ]);

        return $imap;
    }

    // create new
    return MailImapServer::create([
        'id'              => (string) Str::uuid(),
        'organization_id' => $data['organization_id'],
        'mail_server_id'  => $data['mail_server_id'],
        'created_by'      => $data['created_by'],
        'host'            => $data['host'],
        'port'            => $data['port'],
        'encryption'      => $data['encryption'],
        'username'        => $data['username'],
        'password'        => $data['password'],
        'folder'          => $data['folder'] ?? 'INBOX',
        'created_at'      => now(),
        'deleted'         => 0,
    ]);
}
	private function getImapServer(string $id)
	{
		
    return MailImapServer::where('id', $id)
        ->where('organization_id', auth()->user()->organization_id)
        ->where('deleted', 0)
        ->firstOrFail();
	
	}
	public function connectImap(string $imapServerId): bool
	{
			$server = $this->getImapServer($imapServerId);

			$this->openImapConnection($server);

			return true;
		}

	private function openImapConnection($server)
{
	
    if (
        !$server->host ||
        !$server->port ||
        !$server->username ||
        !$server->password
    ) {
        throw new \Exception('IMAP credentials not configured');
    }
	

    $mailbox = sprintf(
        '{%s:%s/imap/%s}%s',
        $server->host,
        $server->port,
        strtolower($server->encryption ?? 'ssl'),
        $server->folder ?? 'INBOX'
    );

    $password = \Crypt::decryptString($server->password);

    $imap = @imap_open($mailbox, $server->username, $password);

    if (!$imap) {
        throw new \Exception(imap_last_error());
    }

    return $imap;
}
public function fetchImapInbox(string $imapServerId, int $limit = 20): array
{
    $server = $this->getImapServer($imapServerId);
    $imap = $this->openImapConnection($server);

    $lastUid = $server->last_uid ?? 0;
    \Log::info("IMAP Sync Start", ['server_id' => $imapServerId, 'last_uid' => $lastUid]);
    
    $syncedCount = 0;
    
    // 1. FORWARD SYNC: Try to get new emails since last sync UID
    $newMsgNos = [];
    if ($lastUid == 0) {
        $total = imap_num_msg($imap);
        // Identify the range of LATEST emails, e.g. 81-100
        if ($total > 0) {
            $start = max(1, $total - $limit + 1);
            $newMsgNos = range($start, $total); // Ascending order: 81, 82 ... 100
        }
    } else {
        $nextUid = $lastUid + 1;
        $newMsgNos = imap_search($imap, "UID $nextUid:*") ?: [];
        sort($newMsgNos); // Ensure Ascending
    }

    $highestUid = $lastUid;
   // dd($imap,$newMsgNos,$lastUid,'mails');
    foreach ($newMsgNos as $msgNo) {
        $uid = imap_uid($imap, $msgNo);
        if (!$uid || $uid <= $lastUid) continue;
        if ($uid > $highestUid) $highestUid = $uid;

        if ($this->syncEmail($imap, $server, $uid)) {
            $syncedCount++;
        }
        
        if ($syncedCount >= $limit) break;
    }

    // 2. BACKWARD SYNC: If we haven't reached the limit with NEW emails, fetch OLD history
    if ($syncedCount < $limit) {
        $lowestUidInDb = MailLog::where('mail_server_id', $server->id)
            ->where('direction', 'incoming')
            ->whereNotNull('imap_uid')
            ->where('deleted', 0)
            ->min('imap_uid');

        if ($lowestUidInDb && $lowestUidInDb > 1) {
            $msgNo = imap_msgno($imap, $lowestUidInDb);
            if ($msgNo > 1) {
                // We want to fetch the block BEFORE the current lowest.
                // e.g. current lowest is 81. We want 20 before that: 61 to 80.
                // But we must PROCESS them 61->80 (Ascending).
                
                $endMsgNo = $msgNo - 1; // 80
                $needed = $limit - $syncedCount;
                $startMsgNo = max(1, $endMsgNo - $needed + 1); // 61
                
                \Log::info("IMAP Backfilling history", ['server_id' => $imapServerId, 'start_msg_no' => $startMsgNo, 'end_msg_no' => $endMsgNo]);
                
                // Loop Ascending
                for ($m = $startMsgNo; $m <= $endMsgNo; $m++) {
                    $uid = imap_uid($imap, $m);
                    if ($uid && $this->syncEmail($imap, $server, $uid)) {
                        $syncedCount++;
                    }
                }
            }
        }
    }

    imap_close($imap);

    if ($highestUid > $lastUid) {
        $server->update([
            'last_uid' => $highestUid,
            'last_sync_at' => now()
        ]);
    }

    \Log::info("IMAP Sync Finished", ['server_id' => $imapServerId, 'newly_synced' => $syncedCount]);

    // 3. FETCH ALL EMAILS AND GROUP BY THREADS
    $allMails = MailLog::where('mail_server_id', $server->id)
        ->where('direction', 'incoming')
        ->where('deleted', 0)
        ->orderBy('created_at', 'desc')
        ->limit(100)
        ->get();

    // Group emails by thread_id
    $threads = [];
    $threadsMap = [];

    foreach ($allMails as $mail) {
        $threadId = $mail->thread_id;
        
        if (!isset($threadsMap[$threadId])) {
            $threadsMap[$threadId] = [
                'thread_id' => $threadId,
                'subject' => $mail->subject,
                'participants' => [],
                'last_message_date' => $mail->created_at,
                'unread_count' => 0,
                'message_count' => 0,
                'messages' => []
            ];
        }

        // Add message to thread
        $threadsMap[$threadId]['messages'][] = [
            'id' => $mail->id,
            'from' => $mail->from_email,
            'to' => $mail->to_email,
            'subject' => $mail->subject,
            'body' => $mail->body,
            'body_preview' => strip_tags(substr($mail->body, 0, 200)),
            'date' => $mail->created_at->toDateTimeString(),
            'is_read' => $mail->is_read,
            'imap_uid' => $mail->imap_uid,
            'message_id' => $mail->message_id
        ];

        // Update thread metadata
        $threadsMap[$threadId]['message_count']++;
        if (!$mail->is_read) {
            $threadsMap[$threadId]['unread_count']++;
        }

        // Track participants
        if (!in_array($mail->from_email, $threadsMap[$threadId]['participants'])) {
            $threadsMap[$threadId]['participants'][] = $mail->from_email;
        }
        if ($mail->to_email && !in_array($mail->to_email, $threadsMap[$threadId]['participants'])) {
            $threadsMap[$threadId]['participants'][] = $mail->to_email;
        }

        // Update last message date (most recent)
        if ($mail->created_at > $threadsMap[$threadId]['last_message_date']) {
            $threadsMap[$threadId]['last_message_date'] = $mail->created_at;
        }
    }

    // Sort messages within each thread by date (ascending for chat-like display)
    foreach ($threadsMap as &$thread) {
        usort($thread['messages'], function($a, $b) {
            return strtotime($a['date']) - strtotime($b['date']);
        });
        
        // Convert last_message_date to string
        $thread['last_message_date'] = $thread['last_message_date']->toDateTimeString();
    }

    // Convert to array and sort threads by last message date (descending)
    $threads = array_values($threadsMap);
    usort($threads, function($a, $b) {
        return strtotime($b['last_message_date']) - strtotime($a['last_message_date']);
    });

    return $threads;
}

public function searchMails(string $imapServerId, array $filters): array
{
    $server = $this->getImapServer($imapServerId);
    
    $query = MailLog::where('mail_server_id', $server->id)
        ->where('direction', 'incoming')
        ->where('deleted', 0);

    if (!empty($filters['keyword'])) {
        $keyword = $filters['keyword'];
        $query->where(function ($q) use ($keyword) {
            $q->where('subject', 'like', "%$keyword%")
              ->orWhere('body', 'like', "%$keyword%");
        });
    }

    if (!empty($filters['from'])) {
        $from = $filters['from'];
        $query->where('from_email', 'like', "%$from%");
    }

    if (!empty($filters['date_from'])) {
        $query->whereDate('created_at', '>=', $filters['date_from']);
    }

    if (!empty($filters['date_to'])) {
        $query->whereDate('created_at', '<=', $filters['date_to']);
    }

    if (isset($filters['is_read'])) {
        $query->where('is_read', $filters['is_read']);
    }

    $limit = $filters['limit'] ?? 20;

    return $query->orderBy('imap_uid', 'desc')
        ->limit($limit)
        ->get()
        ->map(function ($log) {
            return [
                'id' => $log->id,
                'from' => $log->from_email,
                'subject' => $log->subject,
                'date' => $log->created_at->toDateTimeString(),
                'body' => strip_tags($log->body),
                'is_read' => $log->is_read,
                'imap_uid' => $log->imap_uid
            ];
        })
        ->toArray();
}

/**
 * Record when an email is opened via tracking pixel
 */
public function recordOpen(string $token): void
{
    $log = MailLog::where('tracking_token', $token)->first();
    \Log::info("record open log", ['log' => $log, 'token' => $token]);
    if ($log) {
        if (!$log->opened_at) {
            $log->update([
                'is_read' => 1,
                'opened_at' => now()
            ]);
        }
    }
}

public function getThreadMails(string $imapServerId, string $threadId): array
{
    $server = $this->getImapServer($imapServerId);

    return MailLog::where('mail_server_id', $server->id)
        ->where('thread_id', $threadId)
        ->where('deleted', 0)
        ->orderBy('created_at', 'asc') // Chronological order for conversation
        ->get()
        ->map(function ($log) {
            return [
                'id' => $log->id,
                'from' => $log->from_email,
                'subject' => $log->subject,
                'date' => $log->created_at->toDateTimeString(),
                'body' => strip_tags($log->body),
                'is_read' => $log->is_read,
                'imap_uid' => $log->imap_uid,
                'message_id' => $log->message_id,
                'thread_id' => $log->thread_id
            ];
        })
        ->toArray();
}

/**
 * Helper to sync a single email
 */
private function syncEmail($imap, $server, $uid)
{
    // Check if message already exists in log
    $log = MailLog::where('mail_server_id', $server->id)
        ->where('imap_uid', $uid)
        ->where('deleted', 0)
        ->first();

    $overviews = imap_fetch_overview($imap, $uid, FT_UID);
   
    if (empty($overviews)) return false;
    
    $overview = $overviews[0];
    $isRead = !empty($overview->seen) ? 1 : 0;
    
    // Extract headers for threading
    $messageId = $overview->message_id ?? null;
    $inReplyTo = $overview->in_reply_to ?? null;
    $references = $overview->references ?? null;

    \Log::info("Threading Debug [$uid]", [
        'msg_id' => $messageId,
        'in_reply' => $inReplyTo,
        'refs' => $references
    ]);
    
    // Calculate Thread ID
    $threadId = (string) Str::uuid(); // Default to new thread
    
    // 1. Try finding thread via in_reply_to
    if ($inReplyTo) {
        $parent = MailLog::where('mail_server_id', $server->id)
            ->where('message_id', $inReplyTo)
            ->where('deleted', 0)
            ->first();
        
        if ($parent) {
             \Log::info("Parent found via In-Reply-To", ['parent_id' => $parent->id, 'thread_id' => $parent->thread_id]);
             if ($parent->thread_id) {
                $threadId = $parent->thread_id;
             }
        } else {
             \Log::info("Parent NOT found via In-Reply-To", ['search_msg_id' => $inReplyTo]);
        }
    }
    
    // 2. Try finding thread via references (if not found via in_reply_to)
    if ($threadId === null || ($threadId !== null && !isset($parent) && $references)) {
         // Check if we are still on a generated UUID (meaning no parent found yet)
         // Actually we can check if $threadId is the one we generated above. 
         // But simpler: if we didn't match In-Reply-To, try References.
         
         // Let's refine the logic:
         // If we haven't found a parent yet (strict check)
         if (!isset($parent)) {
            $refIds = preg_split('/\s+/', $references, -1, PREG_SPLIT_NO_EMPTY);
            \Log::info("Checking References", ['count' => count($refIds), 'ids' => $refIds]);
            
            if (!empty($refIds)) {
                 $related = MailLog::where('mail_server_id', $server->id)
                    ->whereIn('message_id', $refIds)
                    ->where('deleted', 0)
                    ->first();
                 
                 if ($related) {
                     \Log::info("Related found via References", ['related_id' => $related->id, 'thread_id' => $related->thread_id]);
                     if ($related->thread_id) {
                         $threadId = $related->thread_id;
                     }
                 }
            }
         }
    }

    // Double check: if we generated a NEW thread ID, but maybe this email IS a parent of someone else (out of order arrival?)
    // This is complex and expensive, usually standard flow handles it. 
    // Optimization: Just stick to finding parents.

    // If exists, update read status if changed and return false (not a new email)
    if ($log) {
        $updates = [];
        if ($log->is_read != $isRead) $updates['is_read'] = $isRead;
        // Optionally update threading info if missing
        if (!$log->message_id && $messageId) $updates['message_id'] = $messageId;
        if (!$log->thread_id) $updates['thread_id'] = $threadId;
        
        if (!empty($updates)) $log->update($updates);
        
        return false;
    }

    $body = $this->getEmailBody($imap, $uid);

    $this->createLog(
        $server->organization_id,
        $server->id,
        auth()->id(),
        'incoming',
        $server->username,
        $overview->from ?? null,
        $overview->subject ?? null,
        'success',
        null,
        [
            'message_id' => $messageId,
            'imap_uid'   => $uid,
            'date'       => $overview->date ?? null,
            'folder'     => $server->folder ?? 'INBOX'
        ],
        $body,
        $uid,
        $isRead,
        $messageId,
        $inReplyTo,
        $references,
        $threadId
    );

    return true;
}

/**
 * Helper to fetch email body
 */
    private function getEmailBody($imap, $uid)
    {
        $structure = imap_fetchstructure($imap, $uid, FT_UID);
        
        // Use a recursive helper to find the best body part
        $body = $this->extractBody($imap, $uid, $structure);
        
        return $body ?: '(No Content)';
    }

    private function extractBody($imap, $uid, $structure, $partNumber = null)
    {
        // If it's a simple message with no parts
        if (!isset($structure->parts)) {
            // Check if it's text
            if ($structure->type == 0) { // 0 = TEXT
                return $this->fetchAndDecode($imap, $uid, $partNumber, $structure->encoding);
            }
            return null;
        }

        // It has parts, iterate to find the best content
        // Priority: HTML > Plain Text
        
        $flattenedParts = $this->flattenParts($structure->parts, $partNumber);
        
        // 1. Try to find HTML part
        foreach ($flattenedParts as $part) {
            if ($part['subtype'] === 'HTML') {
                return $this->fetchAndDecode($imap, $uid, $part['number'], $part['encoding']);
            }
        }

        // 2. Fallback to Plain Text
        foreach ($flattenedParts as $part) {
            if ($part['subtype'] === 'PLAIN') {
                return $this->fetchAndDecode($imap, $uid, $part['number'], $part['encoding']);
            }
        }

        return null;
    }

    private function flattenParts($parts, $prefix = '', &$flattened = [])
    {
        foreach ($parts as $index => $part) {
            $number = $prefix ? "$prefix." . ($index + 1) : ($index + 1);
            
            if (isset($part->parts) && !empty($part->parts)) {
                // Recursively go deeper
                $this->flattenParts($part->parts, $number, $flattened);
            } else {
                // It's a leaf part
                $flattened[] = [
                    'number' => $number,
                    'type'   => $part->type,
                    'subtype'=> $part->subtype,
                    'encoding' => $part->encoding
                ];
            }
        }
        return $flattened;
    }

    private function fetchAndDecode($imap, $uid, $partNumber, $encoding)
    {
        // If partNumber is null, it's the whole body (for non-multipart)
        $content = $partNumber 
            ? imap_fetchbody($imap, $uid, $partNumber, FT_UID)
            : imap_body($imap, $uid, FT_UID);

        if (!$content) return '';

        switch ($encoding) {
            case 3: // BASE64
                return base64_decode($content);
            case 4: // QUOTED-PRINTABLE
                return quoted_printable_decode($content);
            default:
                return $content;
        }
    }


	private function createLog($orgId, $serverId, $userId, $direction, $to, $from, $subject, $status, $error = null, array $info = null, $body = null, $imapUid = null, $isRead = 0, $messageId = null, $inReplyTo = null, $references = null, $threadId = null, $trackingToken = null) {
		return MailLog::create([
			'id' => (string) Str::uuid(),
			'organization_id' => $orgId,
			'mail_server_id' => $serverId,
			'created_by' => $userId,
			'direction' => $direction,
			'to_email' => $to,
			'from_email' => $from,
			'subject' => $subject,
			'body' => $body,
			'imap_uid' => $imapUid,
            'message_id' => $messageId,
            'in_reply_to' => $inReplyTo,
            'references' => $references,
            'thread_id' => $threadId,
            'tracking_token' => $trackingToken,
			'is_read' => $isRead,
			'status' => $status,
			'error_message' => $error,
			'info'            => $info,
			'created_at' => now(),
			'deleted' => 0
		]);
	}
}

