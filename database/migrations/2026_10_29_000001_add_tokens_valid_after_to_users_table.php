<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Epoch keamanan: access token dengan created_at <= nilai ini
            // dianggap sudah dicabut (global logout / ganti password / suspend).
            $table->timestamp('tokens_valid_after')->nullable()->after('password_changed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('tokens_valid_after');
        });
    }
};
