<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zapier_connected_apps', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('organization_id', 36)->nullable(false);
            $table->string('external_source', 50)->nullable(false);
            $table->json('modules')->nullable(false);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['organization_id', 'external_source'], 'org_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zapier_connected_apps');
    }
};
