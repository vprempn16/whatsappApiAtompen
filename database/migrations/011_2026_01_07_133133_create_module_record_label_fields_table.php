<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_record_label_fields', function (Blueprint $table) {
            $table->char('id', 36)->nullable(false);
            $table->primary('id');
            $table->char('orgid', 36)->nullable();
            $table->string('module_name', 255)->nullable(false);
            $table->string('field_name', 255)->nullable(false);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_record_label_fields');
    }
};
