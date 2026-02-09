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
            $table->dropColumn([
                'imap_host',
                'imap_port',
                'imap_encryption',
                'imap_username',
                'imap_password',
                'imap_folder',
                'last_imap_sync_at',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
       Schema::table('mail_servers', function (Blueprint $table) {
            $table->string('imap_host')->nullable();
            $table->integer('imap_port')->nullable();
            $table->string('imap_encryption')->nullable();
            $table->string('imap_username')->nullable();
            $table->text('imap_password')->nullable();
            $table->string('imap_folder')->nullable();
            $table->timestamp('last_imap_sync_at')->nullable();
        });  
      
    }
};
