<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oauth_clients', function (Blueprint $table) {
            $table->boolean('trusted')->default(false)->after('grant_types');
            $table->string('backchannel_logout_uri')->nullable()->after('trusted');
            $table->string('frontchannel_logout_uri')->nullable()->after('backchannel_logout_uri');
        });
    }

    public function down(): void
    {
        Schema::table('oauth_clients', function (Blueprint $table) {
            $table->dropColumn(['trusted', 'backchannel_logout_uri', 'frontchannel_logout_uri']);
        });
    }

    public function getConnection(): ?string
    {
        return $this->connection ?? config('passport.connection');
    }
};
