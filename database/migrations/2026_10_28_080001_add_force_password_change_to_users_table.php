<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // true = user wajib mengganti password sebelum bisa mengakses halaman lain
            $table->boolean('must_change_password')->default(false)->after('password');
            // waktu terakhir user mengganti password sendiri
            $table->timestamp('password_changed_at')->nullable()->after('password_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['must_change_password', 'password_changed_at']);
        });
    }
};
