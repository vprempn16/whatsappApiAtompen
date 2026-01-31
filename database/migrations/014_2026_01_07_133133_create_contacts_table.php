<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->char('id', 36)->nullable(false);
            $table->primary('id');
            $table->string('identifier', 255)->nullable();
            $table->string('first_name', 255)->nullable(false);
            $table->string('last_name', 255)->nullable();
            $table->string('phone_number', 255)->nullable(false);
            $table->string('email', 255)->nullable();
            $table->char('organization_id', 36)->nullable(false);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->tinyInteger('deleted')->nullable(false)->default(0);
            $table->char('created_by', 36)->nullable();
            $table->tinyInteger('is_converted_from_lead')->nullable(false)->default(0);
            $table->char('converted_lead_id', 36)->nullable();
            $table->index(['organization_id'], 'contacts_organization_id_foreign');
            $table->index(['deleted'], 'idx_contacts_deleted');
            $table->index(['organization_id', 'deleted'], 'idx_contacts_org_deleted');
            $table->foreign('organization_id', 'contacts_organization_id_foreign')->references('id')->on('organizations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
