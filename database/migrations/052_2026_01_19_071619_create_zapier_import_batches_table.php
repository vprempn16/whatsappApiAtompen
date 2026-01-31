<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zapier_import_batches', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('organization_id', 36)->nullable(false);
            $table->enum('module', ['contacts', 'leads', 'products'])->nullable(false);
            $table->string('external_source', 50)->nullable(false);
            $table->enum('sync_mode', ['initial', 'incremental'])->nullable(false);
            $table->enum('status', ['running', 'completed', 'failed'])->default('running');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->index(['organization_id', 'module', 'status'], 'idx_org_module_status');
            $table->index(['status', 'created_at'], 'idx_status_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zapier_import_batches');
    }
};
