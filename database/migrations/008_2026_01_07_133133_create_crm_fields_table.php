<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_fields', function (Blueprint $table) {
            $table->char('id', 36)->nullable(false);
            $table->primary('id');
            $table->string('modulename', 255)->nullable(false);
            $table->string('fieldname', 255)->nullable(false);
            $table->string('fieldlabel', 255)->nullable(false);
            $table->string('fieldtype', 255)->nullable(false);
            $table->string('tablename', 255)->nullable(false);
            $table->tinyInteger('mandatory')->nullable(false)->default(0);
            $table->string('apifieldname', 255)->nullable(false)->default('');
            $table->integer('displaytype')->nullable(false)->default(1);
            $table->tinyInteger('is_custom_field')->nullable(false)->default(0);
            $table->char('organization_id', 36)->nullable(false)->default('default');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->integer('deleted')->nullable(false)->default(0);
            $table->integer('seq')->nullable(false)->default(0);
            $table->index(['organization_id', 'modulename', 'deleted'], 'idx_crm_fields_org_module_deleted');
            $table->index(['organization_id', 'modulename', 'seq'], 'idx_crm_fields_org_module_seq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_fields');
    }
};
