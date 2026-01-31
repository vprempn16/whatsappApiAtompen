<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->char('id', 36)->nullable(false);
            $table->primary('id');
            $table->char('organization_id', 36)->nullable(false);
            $table->char('contact_id', 36)->nullable();
            $table->char('invoice_id', 36)->nullable();
            $table->char('product_id', 36)->nullable();
            $table->char('folder_id', 36)->nullable();
            $table->char('lead_id', 36)->nullable();
            $table->char('activity_id', 36)->nullable();
            $table->string('title', 255)->nullable(false);
            $table->text('description')->nullable();
            $table->string('local_id', 40)->nullable();
            $table->string('download_url', 255)->nullable();
            $table->string('thumbnail_url', 255)->nullable();
            $table->char('created_by', 36)->nullable(false);
            $table->tinyInteger('deleted')->nullable(false)->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['organization_id', 'deleted'], 'idx_assets_org_deleted');
            $table->index(['organization_id', 'deleted', 'created_at'], 'idx_assets_org_deleted_created');
            $table->index(['organization_id', 'folder_id'], 'idx_assets_org_folder');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
