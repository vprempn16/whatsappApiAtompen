<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->char('id', 36)->nullable(false);
            $table->primary('id');
            $table->string('identifier', 255)->nullable();
            $table->char('invoice_id', 36)->nullable(false);
            $table->decimal('amount', 15, 2)->nullable(false);
            $table->string('payment_method', 255)->nullable(false);
            $table->string('transaction_ref', 255)->nullable();
            $table->date('payment_date')->nullable(false);
            $table->char('created_by', 36)->nullable(false);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->char('organization_id', 36)->nullable();
            $table->tinyInteger('deleted')->nullable(false)->default(0);
            $table->index(['invoice_id'], 'payments_invoice_id_foreign');
            $table->index(['created_by'], 'payments_created_by_foreign');
            $table->index(['organization_id', 'deleted'], 'idx_payments_org_deleted');
            $table->index(['organization_id', 'deleted', 'payment_date'], 'idx_payments_org_deleted_date');
            $table->foreign('created_by', 'payments_created_by_foreign')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('invoice_id', 'payments_invoice_id_foreign')->references('id')->on('invoices')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
