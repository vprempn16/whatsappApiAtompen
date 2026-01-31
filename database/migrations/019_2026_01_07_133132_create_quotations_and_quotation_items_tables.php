<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Create quotations table
        Schema::create('quotations', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            
            $table->string('identifier', 50)->unique();
            $table->char('organization_id', 36)->nullable(false);
            $table->char('customer_id', 36)->nullable(false);
            
            $table->date('quotation_date')->nullable(false);
            $table->date('valid_until')->nullable();
            
            $table->enum('quotation_status', [
                'draft',
                'sent',
                'viewed',
                'revised',
                'accepted',
                'rejected',
                'expired',
                'cancelled'
            ])->nullable(false)->default('draft');
            
            $table->decimal('subtotal', 15, 2)->nullable(false)->default(0.00);
            $table->decimal('discount_amount', 15, 2)->nullable(false)->default(0.00);
            $table->decimal('tax_amount', 15, 2)->nullable(false)->default(0.00);
            $table->decimal('shipping_amount', 15, 2)->nullable(false)->default(0.00);
            $table->decimal('adjustment_amount', 15, 2)->nullable(false)->default(0.00);
            $table->decimal('total_amount', 15, 2)->nullable(false)->default(0.00);
            
            $table->string('tax_type', 50)->nullable();
            $table->tinyInteger('tax_included')->nullable(false)->default(0);
            
            $table->integer('version')->nullable(false)->default(1);
            $table->char('parent_quotation_id', 36)->nullable();
            $table->char('converted_to_invoice_id', 36)->nullable();
            
            $table->char('created_by', 36)->nullable(false);
            $table->char('assigned_to', 36)->nullable();
            
            $table->text('notes')->nullable();
            $table->text('terms_and_conditions')->nullable();
            
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->tinyInteger('deleted')->nullable(false)->default(0);
            
            // Indexes
            $table->index('organization_id', 'idx_quotation_org');
            $table->index('customer_id', 'idx_quotation_customer');
            $table->index('quotation_status', 'idx_quotation_status');
            $table->index(['organization_id', 'deleted'], 'idx_quotation_org_deleted');
            
            // Foreign keys
            $table->foreign('organization_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->foreign('customer_id')->references('id')->on('contacts')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('assigned_to')->references('id')->on('users')->onDelete('set null');
            $table->foreign('parent_quotation_id')->references('id')->on('quotations')->onDelete('set null');
        });
        
        // Create quotation_items table
        Schema::create('quotation_items', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('quotation_id', 36)->nullable(false);
            $table->char('organization_id', 36)->nullable(false);
            
            $table->char('product_id', 36)->nullable();
            $table->text('description')->nullable(false);
            
            $table->decimal('quantity', 15, 2)->nullable(false)->default(1);
            $table->decimal('unit_price', 15, 2)->nullable(false)->default(0.00);
            
            $table->decimal('discount_rate', 5, 2)->nullable()->default(0.00);
            $table->decimal('discount_amount', 15, 2)->nullable()->default(0.00);
            
            $table->decimal('tax_rate', 5, 2)->nullable()->default(0.00);
            $table->decimal('tax_amount', 15, 2)->nullable()->default(0.00);
            
            $table->decimal('line_total', 15, 2)->nullable(false)->default(0.00);
            
            $table->integer('sort_order')->nullable()->default(0);
            
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->tinyInteger('deleted')->nullable(false)->default(0);
            
            // Indexes
            $table->index('quotation_id', 'idx_quotation');
            $table->index('organization_id', 'idx_qi_org');
            $table->index(['organization_id', 'deleted'], 'idx_qi_org_deleted');
            
            // Foreign keys
            $table->foreign('quotation_id', 'fk_quotation_items_quotation')
                ->references('id')
                ->on('quotations')
                ->onDelete('cascade');
            
            $table->foreign('product_id')->references('id')->on('products')->onDelete('set null');
            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quotation_items');
        Schema::dropIfExists('quotations');
    }
};
