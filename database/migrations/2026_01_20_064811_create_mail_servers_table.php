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
	Schema::create('mail_servers', function (Blueprint $table) {
            $table->char('id', 36)->nullable(false);
            $table->primary('id');

            $table->char('organization_id', 36)->nullable(false)->default('default');
            $table->char('created_by', 36)->nullable(false);

            $table->enum('mail_type', ['smtp','imap'])->nullable(false);
            $table->string('host', 255)->nullable(false);
            $table->integer('port')->nullable(false);
            $table->string('username', 255)->nullable(false);
            $table->text('password')->nullable(false);
            $table->enum('encryption', ['ssl','tls','none'])->nullable(false)->default('none');
            $table->tinyInteger('is_active')->nullable(false)->default(1);

            $table->string('from_name', 255)->nullable();
            $table->string('from_email', 255)->nullable();
            $table->string('folder', 255)->nullable(false)->default('INBOX');

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
        Schema::dropIfExists('mail_servers');
    }
};
