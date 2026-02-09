<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_numbering_details', function (Blueprint $table) {
            $table->char('id', 36)->nullable(false);
            $table->primary('id');
            $table->char('organization_id', 36)->nullable(false);
            $table->string('module_name', 255)->nullable(false);
            $table->string('prefix', 255)->nullable(false);
            $table->integer('initial_suffix')->nullable(false)->default(1);
            $table->integer('current_suffix')->nullable(false)->default(1);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['organization_id', 'module_name'], 'module_numbering_details_org_id_module_name_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_numbering_details');
    }
};
