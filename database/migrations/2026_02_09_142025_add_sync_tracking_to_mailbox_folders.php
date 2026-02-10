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
        Schema::table('mailbox_folders', function (Blueprint $table) {
            $table->unsignedBigInteger('last_uid')->nullable()->after('sort_order');
            $table->timestamp('last_sync_at')->nullable()->after('last_uid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mailbox_folders', function (Blueprint $table) {
            $table->dropColumn(['last_uid', 'last_sync_at']);
        });
    }
};
