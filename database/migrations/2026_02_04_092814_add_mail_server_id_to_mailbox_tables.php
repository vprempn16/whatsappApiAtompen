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
        Schema::table('mailbox_labels', function (Blueprint $table) {
            $table->uuid('mail_server_id')->nullable()->after('user_id');
        });

        Schema::table('mail_signatures', function (Blueprint $table) {
            $table->uuid('mail_server_id')->nullable()->after('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mailbox_labels', function (Blueprint $table) {
            $table->dropColumn('mail_server_id');
        });

        Schema::table('mail_signatures', function (Blueprint $table) {
            $table->dropColumn('mail_server_id');
        });
    }
};
