<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->char('id', 36)->nullable(false);
            $table->primary('id');
            $table->string('first_name', 255)->nullable(false);
            $table->string('last_name', 50)->nullable(false);
            $table->integer('is_admin')->nullable(false)->default(0);
            $table->string('email', 255)->nullable(false);
            $table->string('phone', 50)->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password', 255)->nullable(false);
            $table->tinyInteger('is_active')->nullable(false)->default(1);
            $table->string('remember_token', 100)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->char('organization_id', 36)->nullable(false);
            $table->tinyInteger('deleted')->nullable(false)->default(0);
            $table->unique(['email'], 'users_email_unique');
            $table->index(['organization_id', 'deleted'], 'idx_users_org_deleted');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
