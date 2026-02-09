<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
	Schema::table('mail_servers', function (Blueprint $table) {
            $table->string('imap_host', 255)->nullable()->after('encryption');
            $table->integer('imap_port')->nullable()->after('imap_host');
            $table->string('imap_encryption', 50)->nullable()->after('imap_port');
            $table->string('imap_username', 255)->nullable()->after('imap_encryption');
            $table->text('imap_password')->nullable()->after('imap_username');
            $table->string('imap_folder', 100)->nullable()->default('INBOX')->after('imap_password');
            $table->timestamp('last_imap_sync_at')->nullable()->after('imap_folder');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
	  Schema::table('mail_servers', function (Blueprint $table) {
            $table->dropColumn([
                'imap_host',
                'imap_port',
                'imap_encryption',
                'imap_username',
                'imap_password',
                'imap_folder',
                'last_imap_sync_at'
            ]);
        });
    }
};
