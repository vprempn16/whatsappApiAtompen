<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->bigInteger('id', true)->nullable(false);
            $table->primary('id');
            $table->string('name', 255)->nullable(false);
            $table->string('status', 255)->nullable(false);
            $table->text('description')->nullable();
            $table->char('created_by', 36)->nullable(false);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->char('organization_id', 36)->nullable();
            $table->integer('deleted')->nullable()->default(0);
            $table->index(['organization_id'], 'roles_organization_id_foreign');
            $table->index(['organization_id', 'deleted'], 'idx_roles_org_deleted');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
