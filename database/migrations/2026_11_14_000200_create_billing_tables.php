<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 顧客（組織）が販売者になる Stripe の連結アカウント（Stripe Connect Express）
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('stripe_account_id', 64)->nullable()->after('timezone');
            $table->boolean('stripe_charges_enabled')->default(false)->after('stripe_account_id');
            $table->boolean('stripe_details_submitted')->default(false)->after('stripe_charges_enabled');
            // プラットフォームの手数料（%）。NULL ならシステム全体の既定値
            $table->decimal('application_fee_percent', 5, 2)->nullable()->after('stripe_details_submitted');
        });

        Schema::create('billing_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            // このプランで得られる権利（例: premium）。複数のプランが同じ権利を持てる
            $table->string('entitlement', 64);
            $table->unsignedInteger('amount');
            $table->string('currency', 3)->default('jpy');
            $table->string('interval', 8); // month | year
            $table->string('stripe_product_id', 64)->nullable();
            $table->string('stripe_price_id', 64)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->index(['app_id', 'is_active']);
        });

        Schema::table('app_users', function (Blueprint $table) {
            $table->string('stripe_customer_id', 64)->nullable()->after('notification_preferences');
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained()->cascadeOnDelete();
            $table->foreignId('app_user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('billing_plan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 16)->default('stripe');
            $table->string('provider_subscription_id', 64)->unique();
            $table->string('status', 24);
            $table->string('entitlement', 64);
            $table->timestamp('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();
            $table->index(['app_user_id', 'entitlement', 'status']);
        });

        Schema::table('videos', function (Blueprint $table) {
            // この権利（例: premium）を持つユーザーだけが再生できる。NULL なら権利は不要
            $table->string('required_entitlement', 64)->nullable()->after('visibility');
        });
    }

    public function down(): void
    {
        Schema::table('videos', fn (Blueprint $t) => $t->dropColumn('required_entitlement'));
        Schema::dropIfExists('subscriptions');
        Schema::table('app_users', fn (Blueprint $t) => $t->dropColumn('stripe_customer_id'));
        Schema::dropIfExists('billing_plans');
        Schema::table('organizations', fn (Blueprint $t) => $t->dropColumn(['stripe_account_id', 'stripe_charges_enabled', 'stripe_details_submitted', 'application_fee_percent']));
    }
};
