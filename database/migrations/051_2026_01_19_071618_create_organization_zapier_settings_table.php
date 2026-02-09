<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_zapier_settings', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('organization_id', 36)->nullable(false);
            $table->text('zapier_api_key')->nullable(false);
            $table->tinyInteger('contacts_enabled')->default(0);
            $table->tinyInteger('leads_enabled')->default(0);
            $table->tinyInteger('products_enabled')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->unique('organization_id', 'org_zapier_unique');
            $table->index(['organization_id', 'contacts_enabled', 'leads_enabled', 'products_enabled'], 'idx_org_enabled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_zapier_settings');
    }
};
