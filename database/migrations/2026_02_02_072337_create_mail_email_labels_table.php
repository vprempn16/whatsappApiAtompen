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
        Schema::create('mail_email_labels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('mail_log_id');
            $table->uuid('label_id');
            $table->timestamps();
            
            $table->unique(['mail_log_id', 'label_id']);
            $table->index('label_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mail_email_labels');
    }
};
