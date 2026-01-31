<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('searchable_modules', function (Blueprint $table) {
            $table->bigInteger('id')->nullable(false);
            $table->primary('id');
            $table->string('module_name', 255)->nullable(false);
            $table->string('searchable_field', 255)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->string('search_text', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('searchable_modules');
    }
};
