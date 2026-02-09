<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
	    if (!Schema::hasTable('whatsapp_template_field_mappings')) {
		    Schema::create('whatsapp_template_field_mappings', function (Blueprint $table) {
			    $table->char('id', 36)->nullable(false);
			    $table->primary('id');
			    $table->char('organization_id', 36)->nullable(false);
			    $table->char('template_id', 36)->nullable(false);
			    $table->string('template_language', 10)->nullable();
			    $table->string('template_variable', 100)->nullable();
			    $table->string('component_type', 20)->nullable(false)->default('body');
			    $table->integer('button_index')->nullable();
			    $table->integer('button_param_position')->nullable();
			    $table->string('crm_module', 100)->nullable();
			    $table->string('crm_field', 100)->nullable();
			    $table->timestamp('created_at')->nullable();
			    $table->timestamp('updated_at')->nullable();
			    $table->index('template_id');
			    $table->index(['organization_id', 'crm_module'], 'wtfm_org_crm_module_idx');
			    $table->foreign('organization_id', 'whatsapp_template_field_mappings_organization_id_foreign')
	     ->references('id')->on('organizations')->cascadeOnDelete();
			    $table->foreign('template_id', 'whatsapp_template_field_mappings_template_id_foreign')
	     ->references('id')->on('whatsapp_templates')->cascadeOnDelete();
		    });
	    }
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_template_field_mappings');
    }
};
