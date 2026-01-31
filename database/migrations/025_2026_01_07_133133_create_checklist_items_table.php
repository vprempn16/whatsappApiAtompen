<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklist_items', function (Blueprint $table) {
            $table->char('id', 36)->nullable(false);
            $table->primary('id');
            $table->string('identifier', 255)->nullable();
            $table->char('checklist_id', 36)->nullable(false);
            $table->char('template_item_id', 36)->nullable();
            $table->string('item_name', 255)->nullable(false);
            $table->string('item_type', 255)->nullable(false);
            $table->integer('is_mandatory')->nullable(false)->default(0);
            $table->char('assigned_to', 36)->nullable();
            $table->string('status', 255)->nullable(false);
            $table->text('notes')->nullable();
            $table->string('photo_url', 255)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('order_index')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->char('organization_id', 36)->nullable();
            $table->char('created_by', 36)->nullable();
            $table->tinyInteger('deleted')->nullable(false)->default(0);
            $table->index(['checklist_id'], 'checklist_items_checklist_id_foreign');
            $table->index(['template_item_id'], 'checklist_items_template_item_id_foreign');
            $table->index(['assigned_to'], 'checklist_items_assigned_to_foreign');
            $table->foreign('assigned_to', 'checklist_items_assigned_to_foreign')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('checklist_id', 'checklist_items_checklist_id_foreign')->references('id')->on('checklists')->cascadeOnDelete();
            $table->foreign('template_item_id', 'checklist_items_template_item_id_foreign')->references('id')->on('checklist_template_items')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_items');
    }
};
