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
	   if (!Schema::hasColumn('mail_logs', 'info')) {
        Schema::table('mail_logs', function (Blueprint $table) {
            //
		 $table->json('info')->nullable()->after('error_message');
	});
	   }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mail_logs', function (Blueprint $table) {
              $table->dropColumn('info');
        });
    }
};
