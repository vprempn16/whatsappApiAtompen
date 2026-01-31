<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_profile_rel', function (Blueprint $table) {
            $table->bigInteger('id', true)->nullable(false);
            $table->primary('id');
            $table->bigInteger('role_id')->nullable();
            $table->char('organization_id', 36)->nullable();
            $table->integer('profile_id')->nullable();
            $table->index(['role_id'], 'role_permissions_role_id_foreign');
            $table->index(['organization_id'], 'role_permissions_organization_id_foreign');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_profile_rel');
    }
};
