<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
	                            if (!Schema::hasTable('whatsapp_interactives')) {
        Schema::create('whatsapp_interactives', function (Blueprint $table) {
            $table->char('id', 36)->nullable(false);
            $table->primary('id');
            $table->char('organization_id', 36)->nullable(false);
            $table->char('whatsapp_channel_id', 36)->nullable(false);
            $table->string('name', 255)->nullable(false);
            $table->enum('type', ['button', 'list'])->nullable(false);
            $table->text('body')->nullable(false);
            $table->string('crm_module', 255)->nullable();
            $table->string('trigger_event', 255)->nullable();
            $table->tinyInteger('is_active')->nullable(false)->default(1);
            $table->char('created_by', 36)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index('organization_id');
            $table->index('whatsapp_channel_id');
            $table->index('crm_module');
            $table->foreign('organization_id', 'whatsapp_interactives_organization_id_foreign')
                ->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('whatsapp_channel_id', 'whatsapp_interactives_whatsapp_channel_id_foreign')
                ->references('id')->on('whatsapp_channels')->cascadeOnDelete();
	});
				    }
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_interactives');
    }
};
