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

	    if (Schema::hasColumn('mail_imap_servers', 'mail_server_id')) {
		    Schema::table('mail_imap_servers', function (Blueprint $table) {
			    $table->dropColumn('mail_server_id');
		    });
	    }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mail_imap_servers', function (Blueprint $table) {
            //
		  $table->char('mail_server_id', 36)->nullable()->after('organization_id');
        });
    }
};
