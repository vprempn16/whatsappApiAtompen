<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklist_template_items', function (Blueprint $table) {
            $table->char('id', 36)->nullable(false);
            $table->primary('id');
            $table->char('checklist_template_id', 36)->nullable(false);
            $table->string('item_name', 255)->nullable(false);
            $table->string('item_type', 255)->nullable(false);
            $table->tinyInteger('is_mandatory')->nullable(false)->default(0);
            $table->integer('order_index')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->char('organization_id', 36)->nullable();
            $table->char('created_by', 36)->nullable();
            $table->tinyInteger('deleted')->nullable(false)->default(0);
            $table->index(['checklist_template_id'], 'checklist_template_items_checklist_template_id_foreign');
            $table->foreign('checklist_template_id', 'checklist_template_items_checklist_template_id_foreign')->references('id')->on('checklist_templates')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_template_items');
    }
};
