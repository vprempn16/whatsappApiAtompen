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
        Schema::create('workflow_queues', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->uuid('user_id')->nullable();
            $table->string('type'); // e.g., 'send_email'
            $table->json('params')->nullable();
            $table->string('status')->default('pending'); // pending, processing, completed, failed
            $table->integer('priority')->default(0);
            $table->integer('attempts')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->string('related_module')->nullable();
            $table->string('related_record_id')->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_queues');
    }
};
