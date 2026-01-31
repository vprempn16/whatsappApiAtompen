<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comment_rel', function (Blueprint $table) {
            $table->char('id', 36)->nullable(false);
            $table->primary('id');
            $table->char('comment_id', 36)->nullable(false);
            $table->char('parent_id', 36)->nullable();
            $table->string('parent_module', 100)->nullable(false);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['parent_id', 'parent_module'], 'comment_rel_parent_id_parent_module_index');
            $table->index(['comment_id'], 'comment_rel_comment_id_foreign');
            $table->foreign('comment_id', 'comment_rel_comment_id_foreign')->references('id')->on('comments')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comment_rel');
    }
};
