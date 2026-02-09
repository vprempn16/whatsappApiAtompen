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
        Schema::create('mail_drafts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('user_id');
            $table->uuid('mail_server_id')->nullable(); // which account to send from
            
            $table->json('to')->nullable(); // array of recipients
            $table->json('cc')->nullable();
            $table->json('bcc')->nullable();
            $table->string('subject', 500)->nullable();
            $table->longText('body')->nullable();
            
            $table->uuid('reply_to_mail_log_id')->nullable(); // if replying
            $table->uuid('forward_from_mail_log_id')->nullable(); // if forwarding
            
            // CRM linking
            $table->string('related_module', 100)->nullable();
            $table->uuid('related_record_id')->nullable();
            
            $table->timestamps();
            $table->boolean('deleted')->default(false);
            
            $table->index(['organization_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mail_drafts');
    }
};
