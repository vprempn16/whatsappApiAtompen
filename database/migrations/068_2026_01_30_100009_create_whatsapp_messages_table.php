<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->char('id', 36)->nullable(false);
            $table->primary('id');
            $table->char('organization_id', 36)->nullable(false);
            $table->char('whatsapp_channel_id', 36)->nullable();
            $table->string('message_id', 255)->nullable();
            $table->enum('direction', ['incoming', 'outgoing'])->nullable(false);
            $table->string('type', 50)->nullable(false);
            $table->text('message')->nullable();
            $table->string('crm_module', 100)->nullable();
            $table->string('crm_field', 100)->nullable();
            $table->text('crm_field_value')->nullable();
            $table->char('conversation_key', 36)->nullable();
            $table->string('related_module', 255)->nullable();
            $table->string('related_id', 255)->nullable();
            $table->string('status', 50)->nullable(false)->default('open');
            $table->longText('info')->nullable();
            $table->string('media_id', 255)->nullable();
            $table->char('created_by', 36)->nullable();
            $table->tinyInteger('deleted')->nullable(false)->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index('organization_id');
            $table->index('conversation_key');
            $table->index('whatsapp_channel_id');
            $table->index(['organization_id', 'status', 'deleted']);
            $table->foreign('organization_id', 'whatsapp_messages_organization_id_foreign')
                ->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('whatsapp_channel_id', 'whatsapp_messages_whatsapp_channel_id_foreign')
                ->references('id')->on('whatsapp_channels')->nullOnDelete();
            $table->foreign('conversation_key', 'whatsapp_messages_conversation_key_foreign')
                ->references('id')->on('whatsapp_conversations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
    }
};
