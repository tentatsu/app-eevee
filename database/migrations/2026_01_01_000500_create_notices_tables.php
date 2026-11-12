<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->longText('body');
            $table->string('thumbnail_url', 2048)->nullable();
            $table->timestamp('publish_start_at')->nullable();
            $table->timestamp('publish_end_at')->nullable();
            $table->string('target_type', 16)->default('all');
            $table->foreignId('segment_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_pinned')->default(false);
            $table->string('status', 16)->default('draft');
            $table->timestamps();
            $table->index(['app_id', 'status', 'publish_start_at']);
        });

        Schema::create('notice_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('app_user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at');
            $table->unique(['notice_id', 'app_user_id']);
            $table->index('app_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notice_reads');
        Schema::dropIfExists('notices');
    }
};
