<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->bigInteger('id')->nullable(false);
            $table->primary('id');
            $table->bigInteger('role_id')->nullable();
            $table->string('type', 255)->nullable(false);
            $table->tinyInteger('value')->nullable(false);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->char('organization_id', 36)->nullable();
            $table->integer('user_id')->nullable();
            $table->index(['role_id'], 'role_permissions_role_id_foreign');
            $table->index(['organization_id'], 'role_permissions_organization_id_foreign');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
    }
};
