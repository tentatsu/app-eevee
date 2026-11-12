<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('body');
            $table->string('image_url', 2048)->nullable();
            $table->json('data')->nullable();
            $table->foreignId('notification_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('target_type', 16);
            $table->foreignId('segment_id')->nullable()->constrained()->nullOnDelete();
            $table->json('target_user_ids')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->string('status', 16)->default('draft');
            $table->boolean('is_test')->default(false);
            $table->foreignId('parent_campaign_id')->nullable()->constrained('push_campaigns')->nullOnDelete();
            $table->string('source_type', 32)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedInteger('target_count')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('failure_count')->default(0);
            $table->unsignedInteger('invalid_token_count')->default(0);
            $table->unsignedInteger('open_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();
            $table->index(['app_id', 'status']);
            $table->index(['status', 'scheduled_at']);
        });

        Schema::create('push_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('push_campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 512)->nullable();
            $table->string('message_id')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamps();
            // Idempotency: one delivery per (campaign, device)
            $table->unique(['push_campaign_id', 'device_id']);
            $table->index(['push_campaign_id', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_deliveries');
        Schema::dropIfExists('push_campaigns');
    }
};
