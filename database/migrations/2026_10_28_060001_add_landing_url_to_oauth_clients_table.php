<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oauth_clients', function (Blueprint $table) {
            // Halaman awal aplikasi klien — dipakai sebagai tautan
            // "Aplikasi Terhubung" di dashboard user. Null = link belum diatur.
            $table->string('landing_url')->nullable()->after('redirect_uris');
        });
    }

    public function down(): void
    {
        Schema::table('oauth_clients', function (Blueprint $table) {
            $table->dropColumn('landing_url');
        });
    }
};
