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
            if (!Schema::hasColumn('mail_logs', 'attachment_id')) {
                $table->string('attachment_id')->nullable()->after('mail_server_id'); // Store primary/first attachment ID or reference
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mail_logs', function (Blueprint $table) {
            $table->dropColumn('attachment_id');
        });
    }
};
