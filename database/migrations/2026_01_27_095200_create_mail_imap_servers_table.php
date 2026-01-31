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
       Schema::create('mail_imap_servers', function (Blueprint $table) {
    $table->uuid('id')->primary();

    $table->uuid('organization_id');
    $table->uuid('mail_server_id'); // FK to mail_servers
    $table->uuid('created_by');

    $table->string('host');
    $table->integer('port');
    $table->string('username');
    $table->text('password'); // encrypted
    $table->string('encryption')->nullable(); // ssl / tls / null
    $table->string('folder')->default('INBOX');

    $table->boolean('is_active')->default(1);
    $table->timestamps();
    $table->boolean('deleted')->default(0);
});

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mail_imap_servers');
    }
};
