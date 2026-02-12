<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Modules\Api\V1\Mail\Models\MailImapServer;
use App\Services\Mail\MailboxService;
use Illuminate\Support\Facades\Log;

class FetchMailFolders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mail:fetch-folders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch mail folders for all active IMAP accounts';

    protected $mailboxService;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(MailboxService $mailboxService)
    {
        parent::__construct();
        $this->mailboxService = $mailboxService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Starting folder fetch...');

        // Fetch all active IMAP servers
        $servers = MailImapServer::where('deleted', 0)->get();

        $this->info("Found " . $servers->count() . " servers. --> Organization ID: " . $servers->first()->organization_id);

        foreach ($servers as $server) {
            Log::info("Server: {$server->username} ({$server->id}) --> Organization ID: " . $server->organization_id);
            try {
                $this->info("Fetching folders for server: {$server->username} ({$server->id})");

                if (isset($server->is_active) && !$server->is_active) {
                     $this->info("Skipping inactive server: {$server->username}");
                     continue;
                }

                // Sync Folders
                $this->mailboxService->syncMailboxStructure(
                    $server->id, 
                    $server->organization_id, 
                    $server->created_by, 
                    'Folder'
                );

                $this->info("Successfully fetched folders for: {$server->username}");

            } catch (\Throwable $e) {
                $this->error("Failed to fetch folders for {$server->username}: " . $e->getMessage());
                Log::error("FetchMailFolders Failed for {$server->id}: " . $e->getMessage());
            }
        }

        $this->info('Folder fetch completed.');
        return 0;
    }
}
