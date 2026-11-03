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
        Schema::create('client_access', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->uuid('client_id');
            $table->foreignId('opd_id')->nullable();
            $table->boolean('is_allowed')->default(true);
            $table->timestamps();
            
            // Unique constraint to prevent duplicate access entries
            $table->unique(['user_id', 'client_id']);
            
            // Index for faster queries
            $table->index(['user_id', 'is_allowed']);
            $table->index(['client_id', 'is_allowed']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_access');
    }
};