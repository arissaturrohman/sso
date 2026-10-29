<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Nomor WhatsApp (format internasional tanpa "+", contoh: 6281234567890)
            $table->string('whatsapp', 20)->nullable()->after('nip');
            // Waktu terakhir password dikirim via WhatsApp
            $table->timestamp('password_sent_at')->nullable()->after('last_login_ip');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['whatsapp', 'password_sent_at']);
        });
    }
};
