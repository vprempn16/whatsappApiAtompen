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
            if (!Schema::hasColumn('mail_logs', 'snoozed_until')) {
                $table->timestamp('snoozed_until')->nullable()->after('is_starred');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mail_logs', function (Blueprint $table) {
            if (Schema::hasColumn('mail_logs', 'snoozed_until')) {
                $table->dropColumn('snoozed_until');
            }
        });
    }
};
