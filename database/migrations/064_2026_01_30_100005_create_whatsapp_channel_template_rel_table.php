<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_channel_template_rel', function (Blueprint $table) {
            $table->char('id', 36)->nullable(false);
            $table->primary('id');
            $table->char('whatsapp_channels_id', 36)->nullable(false);
            $table->char('whatsapp_template_id', 36)->nullable(false);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['whatsapp_channels_id', 'whatsapp_template_id'], 'whatsapp_channel_template_rel_channel_template_unique');
            $table->foreign('whatsapp_channels_id', 'whatsapp_channel_template_rel_channel_id_foreign')
                ->references('id')->on('whatsapp_channels')->cascadeOnDelete();
            $table->foreign('whatsapp_template_id', 'whatsapp_channel_template_rel_template_id_foreign')
                ->references('id')->on('whatsapp_templates')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_channel_template_rel');
    }
};
