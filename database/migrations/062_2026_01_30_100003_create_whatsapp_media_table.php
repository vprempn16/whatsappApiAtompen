<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_media', function (Blueprint $table) {
            $table->char('id', 36)->nullable(false);
            $table->primary('id');
            $table->char('organization_id', 36)->nullable(false);
            $table->char('whatsapp_channel_id', 36)->nullable();
            $table->string('media_id', 255)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->string('file_name', 255)->nullable();
            $table->string('local_path', 255)->nullable();
            $table->char('created_by', 36)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index('organization_id');
            $table->index('whatsapp_channel_id');
            $table->foreign('organization_id', 'whatsapp_media_organization_id_foreign')
                ->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('whatsapp_channel_id', 'whatsapp_media_whatsapp_channel_id_foreign')
                ->references('id')->on('whatsapp_channels')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_media');
    }
};
