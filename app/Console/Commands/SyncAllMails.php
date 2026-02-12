<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Modules\Api\V1\Mail\Models\MailImapServer;
use App\Services\Mail\MailService;
use Illuminate\Support\Facades\Log;

class SyncAllMails extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mail:sync-all-mails';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Synchronize all emails for active IMAP servers (Unified or Inbox)';

    protected $mailService;

    /**
     * Create a new command instance.
     *
     * @param MailService $mailService
     */
    public function __construct(MailService $mailService)
    {
        parent::__construct();
        $this->mailService = $mailService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Starting global email synchronization...');

        // Fetch all active/undeleted IMAP servers
        $servers = MailImapServer::where('deleted', 0)
            ->where('is_active', 1)
            ->get();

        $this->info("Found " . $servers->count() . " active servers.");

        foreach ($servers as $server) {
            try {
                $this->info("Syncing server: {$server->username} ({$server->id})");
                
                // Perform sync (prioritizes All Mail if available inside syncAllMails)
                $results = $this->mailService->syncAllMails($server->id, 50, $server->organization_id);
                
                $totalSynced = count($results);
                $this->info("Successfully synced {$totalSynced} items for: {$server->username}");

            } catch (\Throwable $e) {
                $this->error("Failed to sync for {$server->username}: " . $e->getMessage());
                Log::error("SyncAllMails Failed for server {$server->id}: " . $e->getMessage());
            }
        }

        $this->info('Global email synchronization completed.');
        return 0;
    }
}
