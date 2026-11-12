<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('app_key', 64)->unique();
            $table->string('app_secret_hash', 64);
            $table->timestamp('app_secret_rotated_at')->nullable();
            $table->string('auth_mode', 16)->default('builtin');
            $table->string('timezone', 64)->nullable();
            // Encrypted (Laravel `encrypted` cast) credentials
            $table->text('firebase_credentials')->nullable();
            $table->string('video_provider', 32)->default('cloudflare_stream');
            $table->text('video_provider_settings')->nullable();
            // Video settings
            $table->unsignedInteger('max_concurrent_streams')->default(2);
            $table->string('concurrency_policy', 16)->default('reject');
            $table->unsignedInteger('playback_token_grace_minutes')->default(10);
            // Push settings
            $table->unsignedInteger('push_rate_per_second')->default(20);
            $table->unsignedInteger('delivery_log_retention_days')->default(90);
            // External auth
            $table->unsignedInteger('external_token_ttl_minutes')->default(60);
            $table->timestamps();
        });

        Schema::create('attribute_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained()->cascadeOnDelete();
            $table->string('key', 64);
            $table->string('type', 16);
            $table->string('label');
            $table->boolean('app_writable')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['app_id', 'key']);
        });

        Schema::create('notification_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained()->cascadeOnDelete();
            $table->string('key', 64);
            $table->string('label');
            $table->boolean('default_enabled')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['app_id', 'key']);
        });

        Schema::create('app_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained()->cascadeOnDelete();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('external_user_id', 191)->nullable();
            $table->string('name')->nullable();
            $table->json('custom_attributes')->nullable();
            $table->json('notification_preferences')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
            $table->unique(['app_id', 'email']);
            $table->unique(['app_id', 'external_user_id']);
        });

        Schema::create('app_user_password_resets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained()->cascadeOnDelete();
            $table->foreignId('app_user_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('installation_id');
            $table->string('fcm_token', 512)->nullable();
            $table->string('platform', 16);
            $table->string('app_version', 32)->nullable();
            $table->unsignedBigInteger('app_version_num')->nullable();
            $table->string('os_version', 32)->nullable();
            $table->string('locale', 32)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->string('push_permission', 16)->default('not_determined');
            $table->boolean('is_test_device')->default(false);
            $table->string('test_label')->nullable();
            $table->timestamp('last_active_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->string('invalidated_reason', 64)->nullable();
            $table->timestamps();
            $table->unique(['app_id', 'installation_id']);
            $table->index(['app_id', 'invalidated_at']);
            $table->index(['app_id', 'platform']);
            $table->index(['app_id', 'last_active_at']);
            $table->index(['app_id', 'is_test_device']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
        Schema::dropIfExists('app_user_password_resets');
        Schema::dropIfExists('app_users');
        Schema::dropIfExists('notification_categories');
        Schema::dropIfExists('attribute_definitions');
        Schema::dropIfExists('apps');
    }
};
