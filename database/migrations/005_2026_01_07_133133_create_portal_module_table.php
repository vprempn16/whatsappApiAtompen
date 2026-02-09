<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_module', function (Blueprint $table) {
            $table->char('id', 36)->nullable(false);
            $table->primary('id');
            $table->string('modulename', 100)->nullable(false);
            $table->string('modulelabel', 150)->nullable(false);
            $table->tinyInteger('is_entity')->nullable()->default(1);
            $table->string('status', 20)->nullable()->default('Active');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->integer('sort_order')->nullable()->default(0);
            $table->unique(['modulename'], 'idx_portal_module_modulename');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_module');
    }
};
