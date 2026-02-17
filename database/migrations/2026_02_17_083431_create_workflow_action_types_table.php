<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('workflow_action_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->nullable()->index(); // Nullable for system-wide actions
            $table->string('action_label'); // e.g., "Send Email"
            $table->string('action_type')->unique(); // e.g., "send_email"
            $table->string('module_name')->nullable(); // e.g., "Mail"
            $table->string('function_path'); // e.g., "App\Modules\Api\V1\Workflow\Actions\SendEmailAction"
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // Seed initial action
        DB::table('workflow_action_types')->insert([
            'id' => Str::uuid(),
            'organization_id' => null,
            'action_label' => 'Send Email',
            'action_type' => 'send_email',
            'module_name' => 'Mail',
            'function_path' => 'App\Modules\Api\V1\Workflow\Actions\SendEmailAction',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_action_types');
    }
};
