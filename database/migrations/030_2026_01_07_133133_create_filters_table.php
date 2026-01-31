<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('filters', function (Blueprint $table) {
            $table->char('id', 36)->nullable(false);
            $table->primary('id');
            $table->tinyInteger('deleted')->nullable(false)->default(0);
            $table->char('organization_id', 36)->nullable();
            $table->string('name', 255)->nullable(false);
            $table->text('description')->nullable();
            $table->string('module_name', 255)->nullable(false);
            $table->char('created_by', 36)->nullable(false);
            $table->tinyInteger('is_shared')->nullable(false)->default(0);
            $table->tinyInteger('is_default')->nullable(false)->default(0);
            $table->json('header_details')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['organization_id'], 'filters_organization_id_foreign');
            $table->index(['module_name', 'organization_id', 'deleted'], 'filters_module_name_organization_id_deleted_index');
            $table->index(['module_name', 'is_default', 'organization_id'], 'filters_module_name_is_default_organization_id_index');
            $table->index(['created_by', 'module_name'], 'filters_created_by_module_name_index');
            $table->index(['deleted'], 'filters_deleted_index');
            $table->index(['module_name'], 'filters_module_name_index');
            $table->index(['is_shared'], 'filters_is_shared_index');
            $table->index(['is_default'], 'filters_is_default_index');
            $table->foreign('organization_id', 'filters_organization_id_foreign')->references('id')->on('organizations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('filters');
    }
};
