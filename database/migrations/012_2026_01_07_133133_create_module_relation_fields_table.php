<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_relation_fields', function (Blueprint $table) {
            $table->char('id', 36)->nullable(false);
            $table->primary('id');
            $table->char('field_id', 36)->nullable(false);
            $table->string('modulename', 100)->nullable(false);
            $table->string('related_module', 100)->nullable(false);
            $table->tinyInteger('deleted')->nullable(false)->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_relation_fields');
    }
};
