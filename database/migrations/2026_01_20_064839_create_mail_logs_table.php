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
	    Schema::create('mail_logs', function (Blueprint $table) {
		    $table->char('id', 36)->nullable(false);
		    $table->primary('id');

		    $table->char('organization_id', 36)->nullable(false)->default('default');
		    $table->char('mail_server_id', 36)->nullable();
		    $table->char('created_by', 36)->nullable(false);

		    $table->enum('direction', ['outgoing','incoming'])->nullable(false);
		    $table->string('to_email', 255)->nullable();
		    $table->string('from_email', 255)->nullable();
		    $table->string('subject', 255)->nullable();

		    $table->enum('status', ['success','failed'])->nullable(false);
		    $table->text('error_message')->nullable();

		    $table->timestamp('created_at')->nullable();
		    $table->timestamp('updated_at')->nullable();
		    $table->integer('deleted')->nullable(false)->default(0);
	    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mail_logs');
    }
};
