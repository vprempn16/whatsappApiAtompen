<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $table) {
            $table->integer('id')->nullable(false);
            $table->primary('id');
            $table->char('organization_id', 36)->nullable();
            $table->string('name', 50)->nullable();
            $table->text('description')->nullable();
            $table->string('status', 50)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->tinyInteger('deleted')->nullable()->default(0);
            $table->index(['organization_id'], 'profiles_organization_id_fk');
            $table->index(['organization_id', 'deleted'], 'idx_profiles_org_deleted');
            $table->foreign('organization_id', 'profiles_organization_id_fk')->references('id')->on('organizations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profiles');
    }
};
