<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklist_templates', function (Blueprint $table) {
            $table->char('id', 36)->nullable(false);
            $table->primary('id');
            $table->string('name', 255)->nullable(false);
            $table->text('description')->nullable();
            $table->string('module', 255)->nullable();
            $table->tinyInteger('is_active')->nullable(false)->default(1);
            $table->char('created_by', 36)->nullable(false);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->char('organization_id', 36)->nullable();
            $table->tinyInteger('deleted')->nullable(false)->default(0);
            $table->index(['created_by'], 'checklist_templates_created_by_foreign');
            $table->foreign('created_by', 'checklist_templates_created_by_foreign')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_templates');
    }
};
