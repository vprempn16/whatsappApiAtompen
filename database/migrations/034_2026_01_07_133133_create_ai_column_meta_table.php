<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_column_meta', function (Blueprint $table) {
            $table->bigInteger('id')->nullable(false);
            $table->primary('id');
            $table->char('table_id', 36)->nullable(false);
            $table->char('crm_field_id', 36)->nullable(false);
            $table->string('semantic_role', 255)->nullable();
            $table->json('semantic_context')->nullable();
            $table->json('value_examples')->nullable();
            $table->tinyInteger('is_identifier')->nullable(false)->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['table_id', 'crm_field_id'], 'uidx_table_column');
            $table->index(['crm_field_id'], 'ai_column_meta_crm_field_id_foreign');
            $table->foreign('crm_field_id', 'ai_column_meta_crm_field_id_foreign')->references('id')->on('crm_fields')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_column_meta');
    }
};
