<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cache', function (Blueprint $table) {
            $table->string('key', 255)->nullable(false);
            $table->primary('key');
            $table->text('value')->nullable(false);
            $table->integer('expiration')->nullable(false);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cache');
    }
};
