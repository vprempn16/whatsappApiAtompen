<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_module_fields', function (Blueprint $table) {
            $table->bigInteger('id', true)->nullable(false);
            $table->primary('id');
            $table->integer('profileid')->nullable(false);
            $table->char('organization_id', 36)->nullable();
            $table->string('modulename', 100)->nullable(false);
            $table->char('field_id', 36)->nullable(false);
            $table->tinyInteger('invisible')->nullable(false)->default(0);
            $table->tinyInteger('readonly')->nullable(false)->default(0);
            $table->tinyInteger('editable')->nullable(false)->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['profileid', 'modulename', 'field_id'], 'pmf_profile_module_field_unique');
            $table->index(['profileid'], 'pmf_profileid_idx');
            $table->index(['modulename'], 'pmf_modulename_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_module_fields');
    }
};
