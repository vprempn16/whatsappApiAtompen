<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zapier_webhook_caches', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('batch_id', 36)->nullable(false);
            $table->char('organization_id', 36)->nullable(false);
            $table->enum('module', ['contacts', 'leads', 'products'])->nullable(false);
            $table->string('external_source', 50)->default('zapier');
            $table->string('external_id', 120);
            $table->unsignedInteger('record_index')->default(0);
            $table->enum('status', ['pending', 'mapped', 'processing', 'processed', 'failed'])->default('pending');
            $table->json('raw_payload')->nullable(false);
            $table->json('mapping')->nullable();
            $table->json('mapped_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['batch_id', 'status'], 'idx_batch_status');
            $table->index(['organization_id', 'module', 'status'], 'idx_org_module_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zapier_webhook_caches');
    }
};
