<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('filter_conditions', function (Blueprint $table) {
            $table->char('id', 36)->nullable(false);
            $table->primary('id');
            $table->char('filter_id', 36)->nullable(false);
            $table->string('field_name', 255)->nullable(false);
            $table->string('operator_key', 50)->nullable(false);
            $table->text('value')->nullable();
            $table->enum('condition_type', ['AND','OR'])->nullable(false)->default('AND');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['filter_id'], 'filter_conditions_filter_id_foreign');
            $table->foreign('filter_id', 'filter_conditions_filter_id_foreign')->references('id')->on('filters')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('filter_conditions');
    }
};
