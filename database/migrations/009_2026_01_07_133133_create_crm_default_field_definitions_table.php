<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_default_field_definitions', function (Blueprint $table) {
            $table->bigInteger('id')->nullable(false);
            $table->primary('id');
            $table->string('organization_id', 200)->nullable();
            $table->string('modulename', 100)->nullable(false);
            $table->string('fieldname', 100)->nullable(false);
            $table->tinyInteger('mandatory')->nullable()->default(0);
            $table->string('fieldlabel', 150)->nullable(false);
            $table->integer('seq')->nullable()->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['organization_id', 'modulename', 'fieldname'], 'uq_field_scope');
            $table->index(['modulename', 'organization_id'], 'idx_module_org');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_default_field_definitions');
    }
};
