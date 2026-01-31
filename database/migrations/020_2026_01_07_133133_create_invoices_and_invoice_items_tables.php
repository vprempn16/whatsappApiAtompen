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
        // Create invoices table
        Schema::create('invoices', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('invoice_number', 50)->unique();
            $table->char('organization_id', 36)->nullable(false);
            $table->char('customer_id', 36)->nullable(false);
            $table->date('invoice_date')->nullable(false);
            $table->date('due_date')->nullable(false);
            $table->enum('invoice_status', [
                'draft',
                'issued',
                'partially_paid',
                'paid',
                'overdue',
                'cancelled',
                'void'
            ])->nullable(false)->default('draft');
            $table->decimal('subtotal', 15, 2)->nullable(false)->default(0.00);
            $table->decimal('discount_amount', 15, 2)->nullable(false)->default(0.00);
            $table->decimal('tax_amount', 15, 2)->nullable(false)->default(0.00);
            $table->decimal('shipping_amount', 15, 2)->nullable(false)->default(0.00);
            $table->decimal('adjustment_amount', 15, 2)->nullable(false)->default(0.00);
            $table->decimal('total_amount', 15, 2)->nullable(false)->default(0.00);
            $table->decimal('amount_paid', 15, 2)->nullable(false)->default(0.00);
            $table->decimal('balance_due', 15, 2)->nullable(false)->default(0.00);
            $table->char('currency_code', 3)->nullable(false);
            $table->decimal('exchange_rate', 10, 6)->nullable()->default(1.000000);
            $table->string('tax_type', 50)->nullable();
            $table->string('tax_registration_number', 50)->nullable();
            $table->char('quotation_id', 36)->nullable();
            $table->char('created_by', 36)->nullable(false);
            $table->char('issued_by', 36)->nullable();
            $table->text('notes')->nullable();
            $table->text('terms_and_conditions')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->tinyInteger('deleted')->nullable(false)->default(0);
            
            $table->index('organization_id', 'idx_invoice_org');
            $table->index('customer_id', 'idx_invoice_customer');
            $table->index('invoice_status', 'idx_invoice_status');
            $table->index(['organization_id', 'deleted'], 'idx_invoice_org_deleted');
            
            $table->foreign('customer_id')->references('id')->on('contacts')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('organization_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->foreign('quotation_id')->references('id')->on('quotations')->onDelete('set null');
            $table->foreign('issued_by')->references('id')->on('users')->onDelete('set null');
        });

        // Create invoice_items table
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('invoice_id', 36)->nullable(false);
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
            
            $table->index('invoice_id', 'idx_ii_invoice');
            $table->index('organization_id', 'idx_ii_org');
            $table->index(['organization_id', 'deleted'], 'idx_ii_org_deleted');
            
            $table->foreign('invoice_id', 'fk_ii_invoice')
                ->references('id')
                ->on('invoices')
                ->onDelete('cascade');
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
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
    }
};
