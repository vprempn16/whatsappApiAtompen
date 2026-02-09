<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklists', function (Blueprint $table) {
            $table->char('id', 36)->nullable(false);
            $table->primary('id');
            $table->string('identifier', 255)->nullable();
            $table->string('name', 255)->nullable(false);
            $table->text('description')->nullable(false);
            $table->string('module', 255)->nullable(false);
            $table->char('record_id', 36)->nullable();
            $table->char('checklist_template_id', 36)->nullable(false);
            $table->string('status', 255)->nullable(false);
            $table->char('created_by', 36)->nullable(false);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->char('organization_id', 36)->nullable();
            $table->decimal('completion_percentage', 3, 0)->nullable()->default(0);
            $table->tinyInteger('deleted')->nullable(false)->default(0);
            $table->index(['checklist_template_id'], 'checklists_checklist_template_id_foreign');
            $table->index(['created_by'], 'checklists_created_by_foreign');
            $table->foreign('checklist_template_id', 'checklists_checklist_template_id_foreign')->references('id')->on('checklist_templates')->cascadeOnDelete();
            $table->foreign('created_by', 'checklists_created_by_foreign')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklists');
    }
};
