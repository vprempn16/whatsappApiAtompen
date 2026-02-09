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
        Schema::create('mailbox_labels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('user_id')->nullable(); // null = org-level shared label
            
            $table->string('name', 100);
            $table->string('color', 7)->default('#3B82F6'); // hex color
            $table->text('description')->nullable();
            
            $table->timestamps();
            $table->boolean('deleted')->default(false);
            
            $table->index('organization_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mailbox_labels');
    }
};
