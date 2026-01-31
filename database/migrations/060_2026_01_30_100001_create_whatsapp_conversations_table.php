<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_conversations', function (Blueprint $table) {
            $table->char('id', 36)->nullable(false);
            $table->primary('id');
            $table->char('organization_id', 36)->nullable(false);
            $table->string('conversation_id', 255)->nullable(false);
            $table->string('phone_number', 255)->nullable(false);
            $table->char('contact_id', 36)->nullable();
            $table->string('status', 50)->nullable(false)->default('open');
            $table->timestamp('last_message_at')->nullable();
            $table->integer('deleted')->nullable(false)->default(0);
            $table->char('created_by', 36)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index('organization_id');
            $table->index('contact_id');
            $table->index(['organization_id', 'phone_number']);
            $table->foreign('organization_id', 'whatsapp_conversations_organization_id_foreign')
                ->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('contact_id', 'whatsapp_conversations_contact_id_foreign')
                ->references('id')->on('contacts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_conversations');
    }
};
