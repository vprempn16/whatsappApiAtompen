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
        Schema::table('mail_logs', function (Blueprint $table) {
            $table->string('message_id')->nullable()->index()->after('imap_uid');
            $table->string('in_reply_to')->nullable()->index()->after('message_id');
            $table->text('references')->nullable()->after('in_reply_to');
            $table->uuid('thread_id')->nullable()->index()->after('references');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mail_logs', function (Blueprint $table) {
            $table->dropColumn(['message_id', 'in_reply_to', 'references', 'thread_id']);
        });
    }
};
