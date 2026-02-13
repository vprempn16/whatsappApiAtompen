<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('error_logs', function (Blueprint $table) {
            $table->id();
            $table->string('error_id', 40)->unique();
            $table->string('error_type', 100)->nullable(false);
            $table->string('error_category', 50)->nullable(false);
            $table->text('message')->nullable(false);
            $table->text('full_message')->nullable();
            $table->text('stack_trace')->nullable();
            $table->string('endpoint', 255)->nullable();
            $table->string('action', 100)->nullable();
            $table->char('user_id', 36)->nullable();
            $table->char('organization_id', 36)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->json('request_payload')->nullable();
            $table->unsignedSmallInteger('http_status')->default(500);
            $table->timestamp('occurred_at')->nullable(false);
            $table->timestamps();

            $table->index('error_id');
            $table->index(['organization_id', 'occurred_at']);
            $table->index(['error_category', 'occurred_at']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('error_logs');
    }
};
