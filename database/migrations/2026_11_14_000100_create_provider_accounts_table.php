<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Oneframe が用意して複数のアプリで共用する外部サービスのアカウント（Cloudflare Stream など）
        Schema::create('provider_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);
            $table->string('name');
            $table->text('settings')->nullable(); // encrypted:array
            $table->string('webhook_token', 64)->unique();
            $table->timestamps();
        });

        Schema::table('apps', function (Blueprint $table) {
            $table->foreignId('video_provider_account_id')->nullable()->after('video_provider_settings')
                ->constrained('provider_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->dropConstrainedForeignId('video_provider_account_id');
        });
        Schema::dropIfExists('provider_accounts');
    }
};
