<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_operators', function (Blueprint $table) {
            $table->bigInteger('id')->nullable(false);
            $table->primary('id');
            $table->string('field_type', 50)->nullable(false);
            $table->string('operator_key', 50)->nullable(false);
            $table->string('operator_label', 100)->nullable(false);
            $table->string('operator_query', 255)->nullable(false);
            $table->text('description')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['field_type'], 'field_operators_field_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_operators');
    }
};
