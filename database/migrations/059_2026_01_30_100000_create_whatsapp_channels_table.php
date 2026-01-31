<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_channels', function (Blueprint $table) {
            $table->char('id', 36)->nullable(false);
            $table->primary('id');
            $table->char('organization_id', 36)->nullable(false);
            $table->string('name', 255)->nullable(false);
            $table->text('desc')->nullable();
            $table->boolean('is_active')->nullable(false)->default(true);
            $table->string('app_id', 255)->nullable(false);
            $table->string('app_secret', 255)->nullable(false);
            $table->string('phone_number_id', 255)->nullable(false);
            $table->string('business_id', 255)->nullable(false);
            $table->text('access_token')->nullable(false);
            $table->char('created_by', 36)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index('organization_id');
            $table->index('is_active');
            $table->foreign('organization_id', 'whatsapp_channels_organization_id_foreign')
                ->references('id')->on('organizations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_channels');
    }
};
