<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('provider_video_id', 128)->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('thumbnail_url', 2048)->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('status', 16)->default('pending_upload');
            $table->string('status_message')->nullable();
            $table->string('visibility', 16)->default('members');
            $table->timestamp('publish_start_at')->nullable();
            $table->timestamp('publish_end_at')->nullable();
            $table->integer('sort_order')->default(0);
            $table->string('category', 64)->nullable();
            $table->json('tags')->nullable();
            $table->timestamps();
            $table->index(['app_id', 'status']);
            $table->unique(['provider', 'provider_video_id']);
        });

        Schema::create('playback_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('app_id')->constrained()->cascadeOnDelete();
            $table->foreignId('video_id')->constrained()->cascadeOnDelete();
            $table->foreignId('app_user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('viewer_key', 191);
            $table->timestamp('started_at');
            $table->timestamp('last_heartbeat_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 16)->nullable();
            $table->unsignedInteger('watched_seconds')->default(0);
            $table->timestamp('token_expires_at');
            $table->timestamps();
            $table->index(['video_id', 'started_at']);
            $table->index(['app_id', 'ended_at']);
        });

        Schema::create('playback_limit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained()->cascadeOnDelete();
            $table->foreignId('video_id')->constrained()->cascadeOnDelete();
            $table->foreignId('app_user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('viewer_key', 191);
            $table->string('policy', 16);
            $table->uuid('kicked_session_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['video_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playback_limit_events');
        Schema::dropIfExists('playback_sessions');
        Schema::dropIfExists('videos');
    }
};
