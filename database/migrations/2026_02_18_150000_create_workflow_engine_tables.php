<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 1. Workflows Table
        Schema::create('workflows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('status')->default(true);
            $table->uuid('created_by')->nullable();
            $table->timestamps();
        });

        // 2. Workflow Triggers Table (Event + Module)
        Schema::create('workflow_triggers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workflow_id')->index();
            $table->uuid('organization_id')->index();
            $table->string('event_type'); // created, updated, deleted
            $table->string('module_name');
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('workflow_id')->references('id')->on('workflows')->onDelete('cascade');
        });

        // 3. Workflow Conditions Table
        Schema::create('workflow_conditions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workflow_id')->index();
            $table->uuid('organization_id')->index();
            $table->string('field_name');
            $table->string('operator'); // ==, !=, contains, >, <, etc.
            $table->text('value')->nullable();
            $table->enum('logic', ['AND', 'OR'])->default('AND');
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('workflow_id')->references('id')->on('workflows')->onDelete('cascade');
        });

        // 4. Workflow Actions Table
        Schema::create('workflow_actions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workflow_id')->index();
            $table->uuid('organization_id')->index();
            $table->uuid('action_type_id')->index(); // References workflow_action_types.id
            $table->json('params')->nullable();
            $table->integer('execution_order')->default(0);
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('workflow_id')->references('id')->on('workflows')->onDelete('cascade');
        });

        // 5. Workflow Logs Table
        Schema::create('workflow_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->uuid('task_id')->nullable(); // References workflow_queues.id
            $table->string('status'); // success, failed
            $table->text('message')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_logs');
        Schema::dropIfExists('workflow_actions');
        Schema::dropIfExists('workflow_conditions');
        Schema::dropIfExists('workflow_triggers');
        Schema::dropIfExists('workflows');
    }
};
