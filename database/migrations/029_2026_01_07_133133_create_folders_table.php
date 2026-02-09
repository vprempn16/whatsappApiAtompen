<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folders', function (Blueprint $table) {
            $table->char('id', 36)->nullable(false);
            $table->primary('id');
            $table->string('identifier', 50)->nullable(false);
            $table->char('organization_id', 36)->nullable(false);
            $table->string('module', 255)->nullable(false);
            $table->string('folder_name', 255)->nullable(false);
            $table->text('folder_description')->nullable();
            $table->char('created_by', 36)->nullable(false);
            $table->tinyInteger('deleted')->nullable(false)->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->integer('is_default')->nullable()->default(0);
            $table->index(['organization_id', 'deleted'], 'idx_folders_org_deleted');
            $table->index(['organization_id', 'deleted', 'module'], 'idx_folders_org_deleted_module');
            $table->index(['organization_id', 'module', 'is_default'], 'idx_folders_org_module_default');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folders');
    }
};
