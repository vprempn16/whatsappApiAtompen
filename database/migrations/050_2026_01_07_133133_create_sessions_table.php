<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id', 255)->nullable(false);
            $table->primary('id');
            $table->bigInteger('user_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->text('payload')->nullable(false);
            $table->integer('last_activity')->nullable(false);
            $table->index(['user_id'], 'sessions_user_id_index');
            $table->index(['last_activity'], 'sessions_last_activity_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
