<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_table_meta', function (Blueprint $table) {
            $table->bigInteger('id')->nullable(false);
            $table->primary('id');
            $table->string('table_name', 255)->nullable(false);
            $table->string('module_name', 255)->nullable();
            $table->json('relationships')->nullable();
            $table->text('ai_purpose')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['table_name'], 'ai_table_meta_table_name_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_table_meta');
    }
};
