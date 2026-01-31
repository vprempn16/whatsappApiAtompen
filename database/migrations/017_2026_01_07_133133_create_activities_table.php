<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->char('id', 36)->nullable(false);
            $table->primary('id');
            $table->string('title', 255)->nullable(false);
            $table->text('description')->nullable();
            $table->enum('activity_type', ['meeting','call','task'])->nullable(false)->default('meeting');
            $table->date('start_date')->nullable(false);
            $table->date('end_date')->nullable();
            $table->time('start_time')->nullable(false);
            $table->time('end_time')->nullable();
            $table->enum('status', ['Scheduled','Completed','Cancelled'])->nullable(false)->default('Scheduled');
            $table->char('created_by', 36)->nullable(false);
            $table->char('organization_id', 36)->nullable(false);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->string('identifier', 50)->nullable();
            $table->tinyInteger('deleted')->nullable(false)->default(0);

            $table->index(['organization_id', 'deleted'], 'idx_activities_org_deleted');
            $table->index(['created_by'], 'idx_activities_created_by');
            $table->index(['status'], 'idx_activities_status');
            $table->index(['start_date'], 'idx_activities_start_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
