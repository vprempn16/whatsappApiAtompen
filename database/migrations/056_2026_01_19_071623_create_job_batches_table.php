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
        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id', 255)->primary();
            $table->string('name', 255)->nullable(false);
            $table->integer('total_jobs')->nullable(false);
            $table->integer('pending_jobs')->nullable(false);
            $table->integer('failed_jobs')->nullable(false);
            $table->longText('failed_job_ids')->nullable(false);
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at')->nullable(false);
            $table->integer('finished_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_batches');
    }
};
