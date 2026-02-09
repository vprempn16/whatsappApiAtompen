<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_actions', function (Blueprint $table) {
            $table->unsignedInteger('id')->nullable(false);
            $table->primary('id');
            $table->string('action_key', 100)->nullable(false);
            $table->string('label', 200)->nullable(false);
            $table->tinyInteger('security_check')->nullable()->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['action_key'], 'action_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_actions');
    }
};
