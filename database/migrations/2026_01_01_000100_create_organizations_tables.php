<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 32)->unique();
            $table->string('name');
            $table->string('status', 16)->default('active');
            $table->string('timezone', 64)->default('Asia/Tokyo');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('organization_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admin_user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16);
            $table->timestamps();
            $table->unique(['organization_id', 'admin_user_id']);
        });

        Schema::create('organization_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('role', 16);
            $table->string('token_hash', 64)->unique();
            $table->string('invited_by')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'email']);
        });

        Schema::create('ip_allow_entries', function (Blueprint $table) {
            $table->id();
            // NULL = system admin panel allowlist
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('cidr', 64);
            $table->string('note')->nullable();
            $table->timestamps();
            $table->index('organization_id');
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('app_id')->nullable()->index();
            $table->string('actor_type', 32);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->string('action', 64);
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('summary')->nullable();
            $table->json('changes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('ip_allow_entries');
        Schema::dropIfExists('organization_invitations');
        Schema::dropIfExists('organization_members');
        Schema::dropIfExists('organizations');
    }
};
