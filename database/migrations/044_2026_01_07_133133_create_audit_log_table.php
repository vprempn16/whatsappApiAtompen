<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table) {
            $table->bigIncrements('audit_id'); 
            $table->string('event_type', 100)->nullable(false);
            $table->string('entity_name', 100)->nullable(false);
            $table->string('entity_id', 255)->nullable();
            $table->string('related_entity_name', 255)->nullable();
            $table->char('related_entity_id', 36)->nullable();
            $table->string('action_by', 100)->nullable(false);
            $table->timestamp('action_timestamp')->nullable(false);
            $table->text('action_details')->nullable();
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->char('organization_id', 36)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->json('more_info')->nullable();
            $table->index(['organization_id', 'action_timestamp'], 'idx_audit_log_org_timestamp');
            $table->index(['organization_id', 'entity_name', 'entity_id'], 'idx_audit_log_org_entity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }
};
