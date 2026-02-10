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
            $table->bigInteger('min_uid')->unsigned()->nullable()->after('last_uid')->comment('The lowest UID synced for this folder to enable recursive backfill');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mailbox_folders', function (Blueprint $table) {
            $table->dropColumn('min_uid');
        });
    }
};
