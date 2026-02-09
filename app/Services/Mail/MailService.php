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
use App\Services\AuditLogService;
use App\Models\FieldModelManager;

class MailService{

    protected $auditLogService;

    public function __construct(AuditLogService $auditLogService)
    {
        $this->auditLogService = $auditLogService;
    }

    /**
     * Get Mail Object (Factory wrapper)
     */
    public function getMailObject(string $module, string $recordId)
    {
        return \App\Services\Mail\MailObject::make($module, $recordId);
    }

    public function createOutgoingServer(array $data)
    {
        $orgId = $data['organization_id'];
        $username = $data['username'];

        // Check for duplicates
        $exists = MailServer::where('organization_id', $orgId)
            ->where('username', $username)
            ->where('deleted', 0)
            ->exists();

        if ($exists) {
            throw new \Exception('Outgoing server with this username already exists.');
        }

        // Validate SMTP credentials
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

        if (!empty($data['password'])) {
            $data['password'] = Crypt::encryptString($data['password']);
        }

        $server = MailServer::create([
            ...$data,
            'id' => (string) Str::uuid(),
            'created_at' => now(),
            'deleted' => 0,
        ]);

        // Audit Log
        $this->auditLogService->create([
            'entity_name' => 'MailServer',
            'entity_id' => $server->id,
            'new_values' => $server->toArray(),
            'old_values' => [],
            'organization_id' => $orgId,
        ], 'Outgoing Mail Server Created');

        return $server;
    }

    public function updateOutgoingServer($id, array $data)
    {
        $orgId = $data['organization_id'];

        $server = MailServer::where('id', $id)
            ->where('organization_id', $orgId)
            ->where('deleted', 0)
            ->first();

        if (!$server) {
            throw new \Exception('Server not found.');
        }

        // Validate SMTP credentials
        // Use new data or fallback to existing
        $host = $data['host'] ?? $server->host;
        $port = $data['port'] ?? $server->port;
        $username = $data['username'] ?? $server->username;
        $password = $data['password'] ?? null; // Raw password if providing new one
        $encryption = $data['encryption'] ?? $server->encryption;
        $fromEmail = $data['from_email'] ?? ($data['username'] ?? $server->username);

        // If password is NOT provided, we need to decrypt existing one for validation? 
        // Or if password is NOT provided, we assume it hasn't changed.
        // But validateSmtpCredentials needs a raw password.
        
        if (empty($password)) {
             try {
                $decryptedPassword = Crypt::decryptString($server->password);
             } catch (\Exception $e) {
                // If we can't decrypt, we can't validate. Force user to re-enter?
                // Or skip validation? User said "validateSmtpCrrdentials is more important".
                // We should validate.
                $decryptedPassword = $server->password; // Fallback if not encrypted (should check)
             }
             $passwordToValidate = $decryptedPassword;
        } else {
             $passwordToValidate = $password;
        }

        $validationResult = $this->validateSmtpCredentials(
            $host,
            $port,
            $username,
            $passwordToValidate,
            $encryption,
            $fromEmail
        );

        if (!$validationResult['valid']) {
            throw new \Exception($validationResult['error']);
        }

        if (!empty($data['password'])) {
            $data['password'] = Crypt::encryptString($data['password']);
        } else {
            unset($data['password']); // Don't overwrite with null/empty
        }

        $oldValues = $server->toArray();

        $server->update([
            ...$data,
            'updated_at' => now(),
        ]);

        // Audit Log
        $this->auditLogService->create([
            'entity_name' => 'MailServer',
            'entity_id' => $server->id,
            'new_values' => $server->fresh()->toArray(),
            'old_values' => $oldValues,
            'is_update' => true,
            'organization_id' => $orgId,
        ], 'Outgoing Mail Server Updated');

        return $server;
    }

    public function getOutgoingServer($serverId, $orgId)
    {
        $server = MailServer::where('id', $serverId)
            ->where('organization_id', $orgId)
            ->where('deleted', 0)
            ->first();

        if (!$server) {
            throw new \Exception('Outgoing server not found.');
        }

        return $server;
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

	public function setOutgoingServer(string $serverId, string $orgId)
	{
		// 1️⃣ Check server exists & belongs to org
		$server = MailServer::where('id', $serverId)
			->where('organization_id', $orgId)
			->where('mail_type', 'smtp')
			->where('deleted', 0)
			->first();

		if (!$server) {
			throw new \Exception('SMTP server not found or invalid');
		}

		// 2️⃣ Disable all outgoing servers for org
		MailServer::where('organization_id', $orgId)
			->where('mail_type', 'smtp')
			->where('deleted', 0)
			->update(['is_active' => 0]);

		// 3️⃣ Enable selected server
		$server->update([
			'is_active'  => 1,
			'updated_at' => now()
		]);

		return true;
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
	private function validateImapCredentials(
		string $host,
		int $port,
		string $username,
		string $password,
		string $encryption,
		string $folder
	): array {
		try {
			$flags = '/imap';
			
			if (!function_exists('imap_open')) {
				throw new \Exception("IMAP extension not enabled in this server.");
			}

			if ($encryption === 'ssl') {
				$flags .= '/ssl';
			} elseif ($encryption === 'tls') {
				$flags .= '/tls';
			}

			$mailbox = sprintf('{%s:%d%s}%s', $host, $port, $flags, $folder);

			$connection = @imap_open($mailbox, $username, $password, OP_HALFOPEN, 1);

			if (!$connection) {
				throw new \Exception(imap_last_error() ?: 'IMAP authentication failed');
			}

			imap_close($connection);

			return ['valid' => true];

		} catch (\Throwable $e) {
			return [
				'valid' => false,
				'error' => 'IMAP validation failed: ' . $e->getMessage()
			];
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
                    $msg = $e->getMessage();

        // ✅ Make message short for frontend
        if (str_contains($msg, '535')) {
            $short = "SMTP authentication failed. Username or password is incorrect.";
        } elseif (str_contains($msg, 'Connection could not be established')) {
            $short = "SMTP connection failed. Please check host/port/encryption.";
        } elseif (str_contains($msg, 'Could not resolve host')) {
            $short = "SMTP host is invalid. Please check the host name.";
        } else {
            $short = "SMTP validation failed. Please verify server settings.";
        }

        // (Optional) log full error for debugging
        \Log::error("SMTP validation error: " . $msg);

        return [
            'valid' => false,
            'error' => $short
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
            $mailable = new GenericMail($data['subject'], $bodyWithPixel);
            
            // Attachments for Mailable
            if (!empty($data['attachments'])) {
                foreach ($data['attachments'] as $attachment) {
                    if (is_array($attachment) && isset($attachment['path'])) {
                        $mailable->attach($attachment['path'], [
                            'as' => $attachment['name'] ?? basename($attachment['path']),
                            'mime' => $attachment['mime_type'] ?? null,
                        ]);
                    }
                }
            }

            Mail::to($data['to'])->send($mailable);

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
				$data['references'] ?? null,
				null, // thread_id
				$trackingToken,
				$data['folder_id'] ?? null
			);

            // Update related_field if present
            if (isset($data['related_field'])) {
                $mailLog->update(['related_field' => $data['related_field']]);
            }

            // Handle Attachments (Save to DB)
            if (!empty($data['attachments'])) {
                $firstAttachmentId = null;
                foreach ($data['attachments'] as $attachment) {
                     if (is_array($attachment) && isset($attachment['path'])) {
                         $attId = $this->saveAttachment($mailLog->id, $orgId, $attachment);
                         if (!$firstAttachmentId) $firstAttachmentId = $attId;
                    }
                }
                
                if ($firstAttachmentId) {
                    $mailLog->update(['attachment_id' => $firstAttachmentId]);
                }
            }

            
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
                    'to_email_details' => [
                        'email' => $mailLog->to_email,
                        'field_name' => $data['related_field'] ?? ($data['field_name'] ?? null),
                         // Add field label if we can pass it, otherwise null
                    ],
                    'from_email_details' => [
                        'from_name' => $fromName,
                        'from_email' => $fromAddress
                    ],
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
    public function createImapServer(array $data)
    {
        // Check duplicates
        $exists = MailImapServer::where('organization_id', $data['organization_id'])
            ->where('username', $data['username'])
            ->where('deleted', 0)
            ->exists();

        if ($exists) {
            throw new \Exception('IMAP server with this username already exists.');
        }

        $validation = $this->validateImapCredentials(
            $data['host'],
            $data['port'],
            $data['username'],
            $data['password'],
            $data['encryption'],
            $data['folder'] ?? 'INBOX'
        );

        if (!$validation['valid']) {
            throw new \Exception($validation['error']);
        }

        // encrypt password
        if (!empty($data['password'])) {
            $data['password'] = Crypt::encryptString($data['password']);
        }

        $imapServer = MailImapServer::create([
            'id'              => (string) Str::uuid(),
            'organization_id' => $data['organization_id'],
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

        // Audit Log
        $this->auditLogService->create([
            'entity_name' => 'MailImapServer',
            'entity_id' => $imapServer->id,
            'new_values' => $imapServer->toArray(),
            'old_values' => [],
            'organization_id' => $data['organization_id'],
        ], 'Incoming Mail Server Created');

        return $imapServer;
    }

    public function updateImapServer($id, array $data)
    {
        $imap = MailImapServer::where('id', $id)
            ->where('organization_id', $data['organization_id'])
            ->where('deleted', 0)
            ->first();

        if (!$imap) {
            throw new \Exception('IMAP server not found.');
        }

        // Prepare credentials for validation
        $host = $data['host'] ?? $imap->host;
        $port = $data['port'] ?? $imap->port;
        $username = $data['username'] ?? $imap->username;
        $encryption = $data['encryption'] ?? $imap->encryption;
        $folder = $data['folder'] ?? ($imap->folder ?? 'INBOX');
        
        $password = $data['password'] ?? null;
        if (empty($password)) {
             try {
                $decryptedPassword = Crypt::decryptString($imap->password);
             } catch (\Exception $e) {
                // If existing password is messed up, we might fail validation if we rely on it.
                // Fallback to raw if possible or assume user must provide new password if validation fails?
                // For now, assume decrypt works.
                $decryptedPassword = $imap->password; 
             }
             $passwordToValidate = $decryptedPassword;
        } else {
             $passwordToValidate = $password;
        }

        $validation = $this->validateImapCredentials(
            $host,
            $port,
            $username,
            $passwordToValidate,
            $encryption,
            $folder
        );

        if (!$validation['valid']) {
            throw new \Exception($validation['error']);
        }

        if (!empty($data['password'])) {
            $data['password'] = Crypt::encryptString($data['password']);
        } else {
            unset($data['password']);
        }

        $oldValues = $imap->toArray();

        $imap->update([
            'host'       => $host,
            'port'       => $port,
            'encryption' => $encryption,
            'username'   => $username,
            'folder'     => $folder,
            ...$data, // includes password if set
            'updated_at' => now(),
        ]);
        
        // Audit Log
        $this->auditLogService->create([
            'entity_name' => 'MailImapServer',
            'entity_id' => $imap->id,
            'new_values' => $imap->fresh()->toArray(),
            'old_values' => $oldValues,
            'is_update' => true,
            'organization_id' => $data['organization_id'],
        ], 'Incoming Mail Server Updated');
        
        return $imap;
    }
    public function getImapServer($serverId, $orgId)
    {
        $server = MailImapServer::where('id', $serverId)
            ->where('organization_id', $orgId)
            ->where('deleted', 0)
            ->first();

        if (!$server) {
            throw new \Exception('IMAP server not found.');
        }

        return $server;
    }

    public function getAllImapServers($orgId)
    {
        return MailImapServer::where('organization_id', $orgId)
            ->where('deleted', 0)
            ->get();
    }

    public function deleteImapServer($serverId, $orgId)
    {
        $server = MailImapServer::where('id', $serverId)
            ->where('organization_id', $orgId)
            ->where('deleted', 0)
            ->first();

        if (!$server) {
            throw new \Exception('IMAP server not found.');
        }

        $server->update([
            'deleted' => 1,
            'updated_at' => now(),
        ]);

        return true;
    }

	private function getImapServerOrFail($id, $orgId = null)
	{
		$query = MailImapServer::where('id', $id)
			->where('deleted', 0);
        
        if ($orgId) {
            $query->where('organization_id', $orgId);
        } elseif (auth()->check()) {
            $query->where('organization_id', auth()->user()->organization_id);
        }

        $server = $query->first();

		if (!$server) {
			throw new \Exception('IMAP server not found or access denied');
		}
		if (!$server->is_active) {
			throw new \Exception('IMAP server is inactive');
		}

		return $server;
	}
	public function connectImap(string $imapServerId): bool
	{
		$server = $this->getImapServerOrFail($imapServerId);

		$this->openImapConnection($server);

		return true;
	}

	private function openImapConnection($server)
	{
		if (!$server->host || !$server->port || !$server->username || !$server->password ) {
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
	public function fetchImapInbox(string $imapServerId, int $limit = 20, ?string $orgId = null): array
	{
		$server = $this->getImapServerOrFail($imapServerId, $orgId);
		$imap = $this->openImapConnection($server);

		$lastUid = $server->last_uid ?? 0;
		\Log::info("IMAP Sync Start", ['server_id' => $imapServerId, 'last_uid' => $lastUid]);

		$syncedCount = 0;

		// 1. FORWARD SYNC: Try to get new emails since last sync UID
		$newMsgNos = [];
        $total = imap_num_msg($imap);

		if ($lastUid == 0) {
			// Identify the range of LATEST emails, e.g. 81-100
			if ($total > 0) {
				$start = max(1, $total - $limit + 1);
				$newMsgNos = range($start, $total); // Ascending order: 81, 82 ... 100
			}
		} else {
            // Find where the last UID is in the current mailbox
			$lastMsgNo = imap_msgno($imap, $lastUid);

            if ($lastMsgNo > 0) {
                // If we found the last synced message, new messages are after it
                if ($lastMsgNo < $total) {
                    $newMsgNos = range($lastMsgNo + 1, $total);
                }
            } else {
                // Last UID not found (e.g. deleted on server), fallback to checking last $limit messages
                \Log::warning("IMAP Sync: Last UID $lastUid not found on server $imapServerId. Resyncing recent.");
                if ($total > 0) {
                    $start = max(1, $total - $limit + 1);
                    $newMsgNos = range($start, $total);
                }
            }
		}

		$highestUid = $lastUid;
		
		
        // Process collected Message Numbers
		foreach ($newMsgNos as $msgNo) {
            $currentUid = imap_uid($imap, $msgNo);

			if (!$currentUid || $currentUid <= $lastUid) continue;
			if ($currentUid > $highestUid) $highestUid = $currentUid;

			if ($this->syncEmail($imap, $server, $currentUid)) {
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

		imap_errors(); // Flush errors
		imap_alerts(); // Flush alerts
		imap_close($imap);

		if ($highestUid > $lastUid) {
			$server->update([
				'last_uid' => $highestUid,
				'last_sync_at' => now()
			]);
		}

		\Log::info("IMAP Sync Finished", ['server_id' => $imapServerId, 'newly_synced' => $syncedCount]);

		// 3. FETCH ALL EMAILS AND GROUP BY THREADS
		$allMails = MailLog::with(['mailRelations']) // Eager load relations
            ->where('mail_server_id', $server->id)
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
            
            // Resolve details
            $relation = $mail->mailRelations->first();
            $fromDetails = ['email' => $mail->from_email, 'name' => null, 'module' => null, 'record_id' => null];
            $toDetails = ['email' => $mail->to_email, 'name' => null];

            if ($relation) {
                // If finding who sent it (incoming)
                if ($mail->direction === 'incoming') {
                     $fromDetails['module'] = $relation->module;
                     $fromDetails['record_id'] = $relation->record_id;
                     // Ideally we fetch the name too, but avoiding N+1.
                     // Front-end can fetch record details if needed, or we can sparsely load if critical.
                }
            }
            
            // Note: $mail->direction here is 'incoming' per query, but good to be generic if logic changes.

			// Add message to thread
			$threadsMap[$threadId]['messages'][] = [
				'id' => $mail->id,
				'from' => $mail->from_email,
				'to' => $mail->to_email,
                'from_email_details' => $fromDetails,
                'to_email_details' => $toDetails,
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
    $server = $this->getImapServerOrFail($imapServerId);
    
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
    $server = $this->getImapServerOrFail($imapServerId);

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

    $mailLog = $this->createLog(
        $server->organization_id,
        $server->id,
        auth()->id(), // Note: If running via job, this might be null. distinct check needed if scheduled.
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

    // Handle Attachments
    $this->processImapAttachments($imap, $uid, $mailLog, $server->organization_id);
    
    // Automatically link to CRM
    $this->linkIncomingMailToCRM($mailLog);

    return true;
}

    private function processImapAttachments($imap, $uid, $mailLog, $orgId)
    {
        $structure = imap_fetchstructure($imap, $uid, FT_UID);
        $attachments = [];
        
        if (isset($structure->parts) && count($structure->parts)) {
             for ($i = 0; $i < count($structure->parts); $i++) {
                 $attachments = array_merge($attachments, $this->extractImapAttachments($imap, $uid, $structure->parts[$i], ((string)($i + 1))));
             }
        }
        
        $firstAttId = null;
        foreach ($attachments as $attData) {
            // Save content to storage
            $storedPath = 'mail-attachments/' . $orgId . '/' . Str::random(40) . '_' . Str::slug($attData['name']); // Flatten name
            \Illuminate\Support\Facades\Storage::put($storedPath, $attData['content']);
            
            $saveData = [
                'name' => $attData['name'],
                'path' => storage_path('app/' . $storedPath), // Absolute path for consistency with upload
                'mime_type' => $attData['mime_type'],
                'size' => $attData['size'],
                'disk' => 'local'
            ];
            
            $attId = $this->saveAttachment($mailLog->id, $orgId, $saveData);
            if (!$firstAttId) $firstAttId = $attId;
        }
        
        if ($firstAttId) {
            $mailLog->update(['attachment_id' => $firstAttId]);
        }
    }

    private function extractImapAttachments($imap, $uid, $part, $partNum)
    {
        $attachments = [];

        if (isset($part->parts)) {
             foreach ($part->parts as $key => $subpart) {
                 $attachments = array_merge($attachments, $this->extractImapAttachments($imap, $uid, $subpart, $partNum . "." . ($key + 1)));
             }
             return $attachments;
        }

        if (isset($part->disposition) && strtoupper($part->disposition) == 'ATTACHMENT') {
            $filename = 'unknown_attachment';
            if (isset($part->dparameters)) {
                foreach ($part->dparameters as $object) {
                    if (strtolower($object->attribute) == 'filename') {
                        $filename = $object->value;
                    }
                }
            }

            if ($filename == 'unknown_attachment' && isset($part->parameters)) {
                foreach ($part->parameters as $object) {
                    if (strtolower($object->attribute) == 'name') {
                        $filename = $object->value;
                    }
                }
            }
            
            $content = imap_fetchbody($imap, $uid, $partNum, FT_UID);
            if ($part->encoding == 3) {
                $content = base64_decode($content);
            } elseif ($part->encoding == 4) {
                $content = quoted_printable_decode($content);
            }
            
            $attachments[] = [
                'name' => $filename,
                'content' => $content,
                'mime_type' => $this->getMimeTypeFromPart($part),
                'size' => strlen($content)
            ];
        }
        
        return $attachments;
    }
    
    private function getMimeTypeFromPart($part)
    {
        $primaryType = ['TEXT', 'MULTIPART', 'MESSAGE', 'APPLICATION', 'AUDIO', 'IMAGE', 'VIDEO', 'OTHER'];
        $type = $primaryType[$part->type] ?? 'APPLICATION';
        $subType = $part->subtype ?? 'OCTET-STREAM';
        return strtolower($type . '/' . $subType);
    }

/**
 * Link incoming email to CRM records (Contact/Lead)
 */
private function linkIncomingMailToCRM($mailLog)
{
    // Only for incoming
    if ($mailLog->direction !== 'incoming') return;
    
    $email = $this->extractEmailAddress($mailLog->from_email);
    if (!$email) return;

    $orgId = $mailLog->organization_id;
    $modules = ['Contact', 'Lead'];

    foreach ($modules as $crmModuleName) {
        try {
            // Use FieldModelManager to get fields
            $fieldManager = FieldModelManager::make($crmModuleName, 'EditView', true);
            $fields = $fieldManager->getFields();

            foreach ($fields as $fieldModel) {
                // Check if field is Email type (case insensitive)
                if (strcasecmp($fieldModel->getFieldType(), 'email') === 0) {
                    $tableName = $fieldModel->getTableName();
                    $fieldName = $fieldModel->getFieldName(); // Database column name

                    // Query the table
                    $record = DB::table($tableName)
                        ->where($fieldName, $email)
                        ->where('organization_id', $orgId)
                        ->where('deleted', 0)
                        ->first();

                    if ($record) {
                        $this->createMailRelation($mailLog, $crmModuleName, $record->id);
                        // Continue to find other matches (other email fields or other modules)
                    }
                }
            }
        } catch (\Exception $e) {
            // Log warning if module loading fails (e.g. module doesn't exist)
            \Log::warning("MailService: Failed to link CRM module {$crmModuleName} - " . $e->getMessage());
        }
    }
}

/**
 * Fetch generic IMAP folders from server
 */
public function getImapFolders(string $serverId, ?string $orgId = null): array
{
    $server = $this->getImapServerOrFail($serverId, $orgId);
    $imap = $this->openImapConnection($server);

    $folders = imap_list($imap, "{".$server->host."}", "*");
    $result = [];

    if (is_array($folders)) {
        foreach ($folders as $folder) {
            // Remove server info from name: {imap.example.com}INBOX -> INBOX
            $name = str_replace("{".$server->host."}", "", $folder);
            // Some servers might return {host:port/ssl...} format, imap_list returns full spec.
            // Better to strip everything before '}'
            $pos = strpos($folder, '}');
            if ($pos !== false) {
                $name = substr($folder, $pos + 1);
            }
            
            // Decode modified UTF-7 (common in IMAP)
            $name = mb_convert_encoding($name, "UTF-8", "UTF7-IMAP");

            $result[] = [
                'name' => $name,
                'path' => $folder // Keep full path for operations if needed? usually helper handles it
            ];
        }
    }

    imap_close($imap);
    return $result;
}

private function extractEmailAddress($string)
{
    // Extract email from "Name <email@example.com>" or just "email@example.com"
    if (filter_var($string, FILTER_VALIDATE_EMAIL)) {
        return $string;
    }
    preg_match('/<(.+)>/', $string, $matches);
    return $matches[1] ?? null;
}

private function createMailRelation($mailLog, $module, $recordId)
{
    // Check if relation already exists
    $exists = \App\Modules\Api\V1\Mail\Models\MailRelation::where('mail_log_id', $mailLog->id)
        ->where('module', $module)
        ->where('record_id', $recordId)
        ->exists();

    if (!$exists) {
        \App\Modules\Api\V1\Mail\Models\MailRelation::create([
            'id' => (string) Str::uuid(),
            'organization_id' => $mailLog->organization_id,
            'module' => $module,
            'record_id' => $recordId,
            'mail_log_id' => $mailLog->id,
            'created_by' => $mailLog->created_by,
            'created_at' => now(),
            'deleted' => 0
        ]);
        
        \Log::info("Linked Mail {$mailLog->id} to $module $recordId");
    }
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


    private function createLog($orgId, $serverId, $userId, $direction, $to, $from, $subject, $status, $error = null, array $info = null, $body = null, $imapUid = null, $isRead = 0, $messageId = null, $inReplyTo = null, $references = null, $threadId = null, $trackingToken = null, $folderId = null) {
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
            'folder_id' => $folderId,
            'tracking_token' => $trackingToken,
			'is_read' => $isRead,
			'status' => $status,
			'error_message' => $error,
			'info'            => $info,
			'created_at' => now(),
			'deleted' => 0
		]);
		}

    public function saveAttachment($mailLogId, $orgId, $attachmentData)
    {
        $attachment = \App\Modules\Api\V1\Mailbox\Models\MailAttachment::create([
            'id' => (string) Str::uuid(),
            'organization_id' => $orgId,
            'mail_log_id' => $mailLogId,
            'filename' => $attachmentData['name'] ?? 'unknown',
            'original_filename' => $attachmentData['original_name'] ?? ($attachmentData['name'] ?? 'unknown'),
            'mime_type' => $attachmentData['mime_type'] ?? 'application/octet-stream',
            'size' => $attachmentData['size'] ?? 0,
            'storage_path' => $attachmentData['path'] ?? '', // Should be relative path from storage/app ideally, but using absolute for now if that's what was passed
            'storage_disk' => $attachmentData['disk'] ?? 'local',
            'created_at' => now(),
            'deleted' => 0
        ]);

        return $attachment->id;
    }
}


