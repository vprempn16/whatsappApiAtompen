<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('global_actions', function (Blueprint $table) {
            $table->integer('id')->nullable(false);
            $table->primary('id');
            $table->string('label', 50)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('global_actions');
    }
};
