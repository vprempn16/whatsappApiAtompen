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
        Schema::create('mailbox_folders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('user_id')->nullable(); // null = org-level folder
            $table->uuid('mail_server_id')->nullable(); // null = applies to all accounts
            
            $table->string('name', 100);
            $table->string('slug', 100); // inbox, sent, drafts, trash, archive, spam, custom-*
            $table->string('type')->default('custom'); // system, custom
            $table->string('icon')->nullable(); // icon class
            $table->integer('sort_order')->default(0);
            
            $table->boolean('is_default')->default(false);
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
        Schema::dropIfExists('mailbox_folders');
    }
};
