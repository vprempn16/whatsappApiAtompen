<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_optins', function (Blueprint $table) {
            $table->char('id', 36)->nullable(false);
            $table->primary('id');
            $table->char('organization_id', 36)->nullable(false);
            $table->string('phone_number', 255)->nullable(false);
            $table->tinyInteger('opted_in')->nullable(false)->default(1);
            $table->timestamp('opted_in_at')->nullable();
            $table->timestamp('opted_out_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index('organization_id');
            $table->index(['organization_id', 'phone_number']);
            $table->foreign('organization_id', 'whatsapp_optins_organization_id_foreign')
                ->references('id')->on('organizations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_optins');
    }
};
