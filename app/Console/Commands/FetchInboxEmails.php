<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Modules\Api\V1\Mail\Models\MailImapServer;
use App\Services\Mail\MailService;
use Illuminate\Support\Facades\Log;

class FetchInboxEmails extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mail:fetch-inbox';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch inbox emails for all active IMAP accounts';

    protected $mailService;

    /**
     * Create a new command instance.
     *
     * @return void
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
        $this->info('Starting inbox fetch...');

        // Fetch all active IMAP servers
        $servers = MailImapServer::where('deleted', 0)
            ->get(); // Assuming all undeleted are active for now, or check is_active if column exists

        // Logic in MailService::getImapServerOrFail checks is_active, so we should probably check here too if column exists
        // Looking at MailImapServer model in file list, and MailService usage, it seems we should try to fetch all.
        // MailService::getImapServerOrFail checks is_active. Let's just fetch all and catch exceptions.
        
        $this->info("Found " . $servers->count() . " servers.");

        foreach ($servers as $server) {
            try {
                $this->info("Fetching for server: {$server->username} ({$server->id})");
                
                // We don't check is_active here because fetchImapInbox calls getImapServerOrFail 
                // which might throw if inactive. We just catch it.
                // However, optimization: if we know it's inactive, skip.
                if (isset($server->is_active) && !$server->is_active) {
                     $this->info("Skipping inactive server: {$server->username}");
                     continue;
                }

                $this->mailService->fetchImapInbox($server->id, 20, $server->organization_id);
                
                $this->info("Successfully fetched for: {$server->username}");

            } catch (\Throwable $e) {
                $this->error("Failed to fetch for {$server->username}: " . $e->getMessage());
                Log::error("FetchInboxEmails Failed for {$server->id}: " . $e->getMessage());
            }
        }

        $this->info('Inbox fetch completed.');
        return 0;
    }
}
