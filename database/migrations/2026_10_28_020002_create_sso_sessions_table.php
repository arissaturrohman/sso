<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sso_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('client_id')->nullable()->constrained('oauth_clients')->nullOnDelete();
            // Hash SHA-256 dari session ID IdP yang bersangkutan (tidak menyimpan session id mentah)
            $table->string('session_hash', 64);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('device_name', 120)->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->timestamp('logged_out_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            // Satu baris per kombinasi (sesi IdP, client). client_id NULL = sesi IdP murni.
            $table->unique(['session_hash', 'client_id'], 'sso_sessions_session_client_unique');
            $table->index(['user_id', 'client_id', 'revoked_at', 'logged_out_at'], 'sso_sessions_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_sessions');
    }
};
