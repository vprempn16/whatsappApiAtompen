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
        if (!Schema::hasTable('mail_relations')) {
            Schema::create('mail_relations', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('organization_id');
                $table->string('module', 100);
                $table->uuid('record_id');
                $table->uuid('mail_log_id');
                $table->uuid('created_by');
                $table->timestamp('created_at');
                $table->tinyInteger('deleted')->default(0);

                $table->index(['organization_id', 'module', 'record_id']);
                $table->index('mail_log_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mail_relations');
    }
};
