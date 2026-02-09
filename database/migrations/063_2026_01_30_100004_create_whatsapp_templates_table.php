<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
	     if (!Schema::hasTable('whatsapp_templates')) {
        Schema::create('whatsapp_templates', function (Blueprint $table) {
            $table->char('id', 36)->nullable(false);
            $table->primary('id');
            $table->char('organization_id', 36)->nullable(false);
            $table->string('business_id', 255)->nullable();
            $table->char('whatsapp_channel_id', 36)->nullable();
            $table->string('module', 255)->nullable();
            $table->char('created_by', 36)->nullable();
            $table->string('template_name', 255)->nullable(false);
            $table->string('language', 20)->nullable(false);
            $table->string('format', 20)->nullable(false);
            $table->string('status', 50)->nullable(false);
            $table->json('components')->nullable();
            $table->string('category', 50)->nullable();
            $table->string('template_id', 255)->nullable(false);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index('whatsapp_channel_id');
            $table->index(['organization_id', 'status']);
            $table->foreign('organization_id', 'whatsapp_templates_organization_id_foreign')
                ->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('whatsapp_channel_id', 'whatsapp_templates_whatsapp_channel_id_foreign')
                ->references('id')->on('whatsapp_channels')->nullOnDelete();
        });
	     
	     }
	}

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_templates');
    }
};
