<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->char('id', 36)->nullable(false);
            $table->primary('id');
            $table->string('identifier', 255)->nullable();
            $table->string('name', 255)->nullable(false);
            $table->string('type', 255)->nullable(false);
            $table->text('description')->nullable();
            $table->string('sku_code', 255)->nullable();
            $table->decimal('cost_price', 15, 2)->nullable();
            $table->decimal('sale_price', 15, 2)->nullable(false);
            $table->string('unit', 255)->nullable();
            $table->decimal('tax_rate', 5, 2)->nullable();
            $table->tinyInteger('is_active')->nullable(false)->default(1);
            $table->char('created_by', 36)->nullable(false);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->char('organization_id', 36)->nullable();
            $table->tinyInteger('deleted')->nullable(false)->default(0);
            $table->index(['created_by'], 'products_created_by_foreign');
            $table->index(['organization_id', 'deleted'], 'idx_products_org_deleted');
            $table->index(['organization_id', 'deleted', 'is_active'], 'idx_products_org_deleted_active');
            $table->index(['organization_id', 'sku_code'], 'idx_products_org_sku');
            $table->foreign('created_by', 'products_created_by_foreign')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
