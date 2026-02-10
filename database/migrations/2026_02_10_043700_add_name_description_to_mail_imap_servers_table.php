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
            if (!Schema::hasColumn('mail_imap_servers', 'name')) {
                $table->string('name')->after('id');
            }
            if (!Schema::hasColumn('mail_imap_servers', 'description')) {
                $table->text('description')->nullable()->after('name');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mail_imap_servers', function (Blueprint $table) {
            $table->dropColumn(['name', 'description']);
        });
    }
};
