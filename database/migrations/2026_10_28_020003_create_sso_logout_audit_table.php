<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sso_logout_audit', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('client_id')->nullable()->constrained('oauth_clients')->nullOnDelete();
            $table->string('triggered_by', 30);            // idp | client_app | admin
            $table->string('method', 30)->default('back_channel'); // back_channel | front_channel
            $table->string('logout_token_jti', 64)->nullable();
            $table->string('status', 30)->nullable();       // sent | delivered | failed
            $table->integer('http_status')->nullable();
            $table->text('response')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_logout_audit');
    }
};
