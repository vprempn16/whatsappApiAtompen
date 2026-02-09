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
            if (!Schema::hasColumn('mail_logs', 'related_field')) {
                $table->string('related_field')->nullable()->after('mail_server_id');
            }
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
