<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->char('id', 36)->nullable(false);
            $table->primary('id');
            $table->text('content')->nullable(false);
            $table->integer('deleted')->nullable(false)->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->char('created_by', 36)->nullable();
            $table->char('organization_id', 36)->nullable();

            $table->index(['organization_id', 'deleted'], 'idx_comments_org_deleted');
            $table->index(['created_by'], 'idx_comments_created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
