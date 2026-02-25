<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Update workflow_action_types table
        Schema::table('workflow_action_types', function (Blueprint $table) {
            if (!Schema::hasColumn('workflow_action_types', 'function_class')) {
                $table->string('function_class')->nullable()->after('function_path');
            }
        });

        // Copy data from function_path to function_class
        DB::table('workflow_action_types')->update([
            'function_class' => DB::raw('function_path')
        ]);

        Schema::table('workflow_action_types', function (Blueprint $table) {
            if (Schema::hasColumn('workflow_action_types', 'module_name')) {
                $table->dropColumn('module_name');
            }
        });

        // 2. Create workflow_actiontype_module_rel table
        Schema::create('workflow_actiontype_module_rel', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('action_type_id');
            $table->uuid('module_id');
            $table->timestamps();

            $table->foreign('action_type_id', 'wa_rel_action_type_foreign')
                ->references('id')->on('workflow_action_types')
                ->onDelete('cascade');

            // Note: portal_module table uses 'id' as primary key (string/uuid based on model)
            $table->foreign('module_id', 'wa_rel_module_foreign')
                ->references('id')->on('portal_module')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_actiontype_module_rel');

        Schema::table('workflow_action_types', function (Blueprint $table) {
            $table->string('module_name')->nullable()->after('action_type');
            $table->dropColumn('function_class');
        });
    }
};
