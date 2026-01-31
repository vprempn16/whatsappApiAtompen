<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zapier_request_logs', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('organization_id', 36)->nullable(false);
            $table->enum('module', ['contacts', 'leads', 'products'])->nullable(false);
            $table->string('external_source', 50)->nullable(false);
            $table->string('external_id', 100);
            $table->enum('sync_mode', ['initial', 'incremental'])->nullable(false);
            $table->enum('status', ['success', 'failed'])->nullable(false);
            $table->text('error_message')->nullable();
            $table->json('payload')->nullable(false);
            $table->timestamp('created_at')->nullable();

            $table->index(['organization_id', 'module', 'external_id'], 'idx_org_module_external');
            $table->index(['organization_id', 'module', 'created_at'], 'idx_org_module_created');
            $table->index(['status', 'created_at'], 'idx_status_created');
            $table->unique(['organization_id', 'module', 'external_id'], 'idx_unique_org_module_external');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zapier_request_logs');
    }
};
