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
        Schema::create('jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('id_new', 36)->nullable();
            $table->string('queue', 255)->nullable(false);
            $table->longText('payload')->nullable(false);
            $table->unsignedTinyInteger('attempts')->nullable(false);
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at')->nullable(false);
            $table->unsignedInteger('created_at')->nullable(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jobs');
    }
};
