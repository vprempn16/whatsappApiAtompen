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
        Schema::table('mail_imap_servers', function (Blueprint $table) {
            if (!Schema::hasColumn('mail_imap_servers', 'min_uid')) {
                $table->unsignedBigInteger('min_uid')->nullable()->after('last_uid')->comment('The lowest UID synced to enable progressive forward sync');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mail_imap_servers', function (Blueprint $table) {
            if (Schema::hasColumn('mail_imap_servers', 'min_uid')) {
                $table->dropColumn('min_uid');
            }
        });
    }
};
