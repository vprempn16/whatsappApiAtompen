<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_interactive_items', function (Blueprint $table) {
            $table->char('id', 36)->nullable(false);
            $table->primary('id');
            $table->char('interactive_id', 36)->nullable(false);
            $table->char('organization_id', 36)->nullable(false);
            $table->enum('item_type', ['button', 'list'])->nullable(false);
            $table->string('item_key', 100)->nullable(false);
            $table->string('title', 255)->nullable(false);
            $table->text('description')->nullable();
            $table->string('section', 255)->nullable();
            $table->integer('sort_order')->nullable(false)->default(0);
            $table->enum('next_action_type', [
                'reply_text',
                'send_template',
                'send_interactive',
                'start_flow',
                'assign_module',
                'close_chat'
            ])->nullable();
            $table->string('next_action_value', 255)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index('item_key');
            $table->foreign('interactive_id', 'whatsapp_interactive_items_interactive_id_foreign')
                ->references('id')->on('whatsapp_interactives')->cascadeOnDelete();
            $table->foreign('organization_id', 'whatsapp_interactive_items_organization_id_foreign')
                ->references('id')->on('organizations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_interactive_items');
    }
};
