<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_generated_queries_org_rel', function (Blueprint $table) {
            $table->char('id', 36)->nullable(false);
            $table->primary('id');
            $table->char('query_id', 36)->nullable(false);
            $table->char('organization_id', 36)->nullable(false);
            $table->char('user_id', 36)->nullable();
            $table->json('more_info')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['query_id'], 'idx_query_id');
            $table->foreign('query_id', 'fk_query_id')->references('id')->on('ai_generated_queries')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_generated_queries_org_rel');
    }
};
