<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_relations', function (Blueprint $table) {
            $table->char('id', 36)->nullable(false);
            $table->primary('id');
            $table->char('activity_id', 36)->nullable(false);
            $table->string('relation_type', 50)->nullable(false);
            $table->string('entity_type', 50)->nullable(false);
            $table->char('entity_id', 36)->nullable(false);
            $table->char('organization_id', 36)->nullable(false);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->tinyInteger('deleted')->nullable(false)->default(0);
            $table->index(['activity_id'], 'idx_activity_id');
            $table->index(['relation_type'], 'idx_relation_type');
            $table->index(['entity_type', 'entity_id'], 'idx_entity');
            $table->index(['relation_type', 'entity_type', 'entity_id'], 'idx_relation_entity');
            $table->index(['organization_id', 'deleted'], 'idx_activity_relations_org_deleted');
            $table->index(['organization_id', 'entity_type', 'entity_id', 'deleted'], 'idx_activity_relations_org_entity');
            $table->foreign('activity_id', 'activity_relations_activity_id_foreign')->references('id')->on('activities')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_relations');
    }
};
