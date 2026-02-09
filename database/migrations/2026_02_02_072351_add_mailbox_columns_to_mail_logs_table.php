<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('mail_logs', function (Blueprint $table) {
            $table->uuid('folder_id')->nullable()->after('thread_id');
            $table->boolean('is_starred')->default(false)->after('is_read');
            $table->boolean('is_archived')->default(false)->after('is_starred');
            $table->timestamp('trashed_at')->nullable()->after('updated_at');
            $table->timestamp('archived_at')->nullable()->after('trashed_at');
            
            $table->index('folder_id');
            $table->index('is_starred');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mail_logs', function (Blueprint $table) {
            //
        });
    }
};
