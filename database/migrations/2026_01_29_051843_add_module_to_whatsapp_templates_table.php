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
	    if (!Schema::hasColumn('whatsapp_templates', 'module')) {
		    Schema::table('whatsapp_templates', function (Blueprint $table) {
			    $table->string('module')
	     ->nullable()
	     ->after('whatsapp_channel_id');
		    });
	    }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_templates', function (Blueprint $table) {
              $table->dropColumn('module');
        });
    }
};
