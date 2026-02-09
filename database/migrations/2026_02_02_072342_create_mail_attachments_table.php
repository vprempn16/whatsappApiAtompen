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
        Schema::create('mail_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('mail_log_id');
            
            $table->string('filename', 255);
            $table->string('original_filename', 255);
            $table->string('mime_type', 100);
            $table->bigInteger('size'); // bytes
            $table->string('storage_path', 500);
            $table->string('storage_disk')->default('local'); // local, s3, etc.
            
            $table->timestamps();
            $table->boolean('deleted')->default(false);
            
            $table->index('mail_log_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mail_attachments');
    }
};
