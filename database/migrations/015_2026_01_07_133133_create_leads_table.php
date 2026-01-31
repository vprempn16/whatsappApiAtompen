<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->char('id', 36)->nullable(false);
            $table->primary('id');
            $table->string('identifier', 255)->nullable();
            $table->string('first_name', 255)->nullable();
            $table->string('last_name', 255)->nullable();
            $table->string('phone_number', 255)->nullable(false);
            $table->string('email', 255)->nullable();
            $table->char('organization_id', 36)->nullable(false);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->tinyInteger('deleted')->nullable(false)->default(0);
            $table->char('created_by', 36)->nullable();
            $table->integer('is_converted')->nullable()->default(0);
            $table->char('converted_contact_id', 36)->nullable();
            $table->index(['organization_id'], 'leads_organization_id_foreign');
            $table->index(['deleted'], 'idx_leads_deleted');
            $table->index(['organization_id', 'deleted'], 'idx_leads_org_deleted');
            $table->index(['is_converted'], 'idx_leads_converted');
            $table->foreign('organization_id', 'leads_organization_id_foreign')->references('id')->on('organizations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
