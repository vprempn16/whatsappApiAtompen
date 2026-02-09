<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('global_search_index', function (Blueprint $table) {
            $table->char('id', 36)->nullable(false);
            $table->primary('id');
            $table->char('organization_id', 36);
            $table->string('module_name', 255)->nullable(false);
            $table->char('record_id', 36)->nullable(false);
            $table->text('search_text')->nullable();
            $table->string('label', 255)->nullable(false);
            $table->integer('deleted')->nullable(false)->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->char('created_by', 36)->nullable();
            $table->index(['organization_id'], 'global_search_index_organization_id_foreign');
            $table->index(['module_name', 'organization_id', 'deleted'], 'idx_gsi_module_org_deleted');
            $table->index(['record_id'], 'idx_gsi_record');
            $table->foreign('organization_id', 'global_search_index_organization_id_foreign')->references('id')->on('organizations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('global_search_index');
    }
};
