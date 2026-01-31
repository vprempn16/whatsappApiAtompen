<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('crm_fields')) {
            return;
        }

        // Payment: ensure invoiceId is visible and mandatory in CreateView
        DB::table('crm_fields')
            ->where('modulename', 'Payment')
            ->where('apifieldname', 'invoiceId')
            ->update([
                'displaytype' => 1,
                'mandatory' => 1,
            ]);

        // Quotation: customer is required by DB, make it mandatory in form
        DB::table('crm_fields')
            ->where('modulename', 'Quotation')
            ->where('apifieldname', 'customerId')
            ->update([
                'mandatory' => 1,
            ]);

        DB::table('crm_fields')
            ->where('modulename', 'Quotation')
            ->where('apifieldname', 'quotationDate')
            ->update([
                'mandatory' => 1,
            ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('crm_fields')) {
            return;
        }

        DB::table('crm_fields')
            ->where('modulename', 'Payment')
            ->where('apifieldname', 'invoiceId')
            ->update([
                'displaytype' => 2,
            ]);

        DB::table('crm_fields')
            ->where('modulename', 'Quotation')
            ->where('apifieldname', 'customerId')
            ->update([
                'mandatory' => 0,
            ]);

        DB::table('crm_fields')
            ->where('modulename', 'Quotation')
            ->where('apifieldname', 'quotationDate')
            ->update([
                'mandatory' => 0,
            ]);
    }
};
