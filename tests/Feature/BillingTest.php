<?php

use App\Enums\OrgRole;
use App\Enums\VideoStatus;
use App\Models\App;
use App\Models\AppUser;
use App\Models\BillingPlan;
use App\Models\Device;
use App\Models\Organization;
use App\Models\ProviderAccount;
use App\Models\Subscription;
use App\Models\Video;
use App\Services\Billing\StripeClient;
use App\Services\Segments\SegmentQuery;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    Redis::connection()->flushdb();
    Http::preventStrayRequests();
    ProviderAccount::query()->create(['provider' => 'stripe', 'name' => 'Stripe', 'settings' => ['secret_key' => 'sk_test_platform', 'webhook_secret' => 'whsec_test', 'fee_percent' => '10', 'country' => 'JP']]);
    $this->org = Organization::factory()->create(['slug' => 'acme', 'stripe_account_id' => 'acct_org', 'stripe_charges_enabled' => true, 'stripe_details_submitted' => true]);
    $this->clientApp = App::factory()->for($this->org)->create();
    $this->plan = BillingPlan::query()->create(['app_id' => $this->clientApp->id, 'name' => 'プレミアム', 'entitlement' => 'premium', 'amount' => 500, 'currency' => 'jpy', 'interval' => 'month', 'stripe_product_id' => 'prod_1', 'stripe_price_id' => 'price_1']);
    $this->user = AppUser::factory()->for($this->clientApp)->create(['email' => 'u@example.com']);
    $this->token = $this->user->createToken('app')->plainTextToken;
});

function stripeWebhook(array $event, string $secret = 'whsec_test'): array
{
    $body = json_encode($event);
    $t = time();

    return [$body, ['HTTP_STRIPE_SIGNATURE' => "t={$t},v1=".hash_hmac('sha256', "{$t}.{$body}", $secret), 'CONTENT_TYPE' => 'application/json']];
}

function stripeSubscription(string $status, array $meta, int $periodEnd): array
{
    return ['id' => 'sub_1', 'object' => 'subscription', 'status' => $status, 'cancel_at_period_end' => false, 'canceled_at' => null, 'metadata' => $meta,
        'items' => ['data' => [['current_period_end' => $periodEnd]]]];
}

it('stores the platform keys encrypted and never shows them', function () {
    actingAsSystemAdmin();
    $this->put('/system/billing', ['secret_key' => 'sk_live_newkey', 'webhook_secret' => '', 'fee_percent' => '12.5', 'country' => 'jp'])->assertSessionHas('status');

    $account = ProviderAccount::query()->where('provider', 'stripe')->sole();
    expect($account->setting('secret_key'))->toBe('sk_live_newkey')
        ->and($account->setting('webhook_secret'))->toBe('whsec_test')
        ->and($account->setting('fee_percent'))->toBe('12.5')
        ->and($account->setting('country'))->toBe('JP');
    expect(DB::table('provider_accounts')->where('provider', 'stripe')->value('settings'))->not->toContain('sk_live_newkey');
    $this->get('/system/billing')->assertOk()->assertDontSee('sk_live_newkey')->assertSee(route('webhooks.stripe'));

    $this->put('/system/billing', ['secret_key' => 'not-a-key', 'fee_percent' => '10', 'country' => 'JP'])->assertSessionHasErrors('secret_key');
});

it('lets an owner start Stripe onboarding, but not an editor', function () {
    $org = Organization::factory()->create(['slug' => 'beta']);
    Http::fake([
        'api.stripe.com/v1/accounts' => Http::response(['id' => 'acct_new']),
        'api.stripe.com/v1/account_links' => Http::response(['url' => 'https://connect.stripe.com/setup/e/acct_new/xyz']),
    ]);

    actingAsOrgMember($org, OrgRole::Editor);
    $this->post('/beta/billing/connect')->assertForbidden();

    actingAsOrgMember($org, OrgRole::Owner);
    $this->post('/beta/billing/connect')->assertRedirect('https://connect.stripe.com/setup/e/acct_new/xyz');
    expect($org->fresh()->stripe_account_id)->toBe('acct_new');
    Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://api.stripe.com/v1/accounts' && $r['type'] === 'express' && $r['country'] === 'JP'
        && $r['capabilities[transfers][requested]'] === 'true' && $r->hasHeader('Authorization', 'Bearer sk_test_platform') && $r->hasHeader('Idempotency-Key'));
    Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/account_links') && $r['account'] === 'acct_new' && $r['return_url'] === route('org.billing.return', $org));

    // 戻ってきたら状態を取得する
    Http::fake(['api.stripe.com/v1/accounts/acct_new' => Http::response(['id' => 'acct_new', 'charges_enabled' => true, 'details_submitted' => true])]);
    $this->get('/beta/billing/return')->assertRedirect('/beta/billing');
    expect($org->fresh()->stripe_charges_enabled)->toBeTrue();
});

it('creates the Stripe product and price for a new plan', function () {
    Http::fake([
        'api.stripe.com/v1/products' => Http::response(['id' => 'prod_new']),
        'api.stripe.com/v1/prices' => Http::response(['id' => 'price_new']),
    ]);
    actingAsOrgMember($this->org, OrgRole::Admin);
    $this->post("/acme/apps/{$this->clientApp->id}/billing/plans", ['name' => '年額', 'amount' => 5000, 'interval' => 'year', 'entitlement' => 'premium'])->assertSessionHas('status');

    $plan = BillingPlan::query()->where('name', '年額')->sole();
    expect($plan->stripe_price_id)->toBe('price_new')->and($plan->app_id)->toBe($this->clientApp->id);
    Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/prices') && $r['unit_amount'] == 5000 && $r['currency'] === 'jpy' && $r['recurring[interval]'] === 'year' && $r['product'] === 'prod_new');

    // 編集者はプランを作れない
    actingAsOrgMember($this->org, OrgRole::Editor);
    $this->post("/acme/apps/{$this->clientApp->id}/billing/plans", ['name' => 'x', 'amount' => 100, 'interval' => 'month', 'entitlement' => 'premium'])->assertForbidden();
});

it('lists plans only when the organization can accept payments', function () {
    $this->getJson("/api/v1/apps/{$this->clientApp->app_key}/billing/plans")->assertOk()
        ->assertJsonPath('data.0.id', $this->plan->id)->assertJsonPath('data.0.price_label', '500円 / 月')->assertJsonPath('data.0.subscribed', false);

    $this->org->update(['stripe_charges_enabled' => false]);
    $this->getJson("/api/v1/apps/{$this->clientApp->app_key}/billing/plans")->assertOk()->assertJsonCount(0, 'data');
});

it('creates a checkout session that pays the organization minus the platform fee', function () {
    Http::fake([
        'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_1']),
        'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_1']),
    ]);
    $url = "/api/v1/apps/{$this->clientApp->app_key}/billing/checkout";
    $this->postJson($url, ['plan_id' => $this->plan->id])->assertUnauthorized();

    $this->withToken($this->token)->postJson($url, ['plan_id' => $this->plan->id])->assertCreated()->assertJsonPath('data.url', 'https://checkout.stripe.com/c/pay/cs_1');
    expect($this->user->fresh()->stripe_customer_id)->toBe('cus_1');
    Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/checkout/sessions')
        && $r['mode'] === 'subscription' && $r['customer'] === 'cus_1' && $r['line_items[0][price]'] === 'price_1'
        && $r['subscription_data[transfer_data][destination]'] === 'acct_org' && $r['subscription_data[application_fee_percent]'] == 10
        && $r['subscription_data[metadata][app_user_id]'] == $this->user->id && $r['subscription_data[metadata][entitlement]'] === 'premium'
        && $r['success_url'] === route('billing.complete', ['app_key' => $this->clientApp->app_key]));
});

it('uses the per-organization fee when set', function () {
    $this->org->update(['application_fee_percent' => 5.5]);
    Http::fake([
        'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_1']),
        'api.stripe.com/v1/checkout/sessions' => Http::response(['url' => 'https://checkout.stripe.com/x']),
    ]);
    $this->withToken($this->token)->postJson("/api/v1/apps/{$this->clientApp->app_key}/billing/checkout", ['plan_id' => $this->plan->id])->assertCreated();
    Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/checkout/sessions') && $r['subscription_data[application_fee_percent]'] == 5.5);
});

it('does not sell a plan of another app', function () {
    $other = App::factory()->create();
    $otherPlan = BillingPlan::query()->create(['app_id' => $other->id, 'name' => 'x', 'entitlement' => 'premium', 'amount' => 100, 'currency' => 'jpy', 'interval' => 'month', 'stripe_price_id' => 'price_x']);
    $this->withToken($this->token)->postJson("/api/v1/apps/{$this->clientApp->app_key}/billing/checkout", ['plan_id' => $otherPlan->id])
        ->assertNotFound()->assertJsonPath('error.code', 'plan_not_found');
});

it('grants the entitlement from the webhook and unlocks paid videos', function () {
    $video = Video::withoutGlobalScopes()->create(['app_id' => $this->clientApp->id, 'provider' => 'cloudflare_stream', 'provider_video_id' => 'uid', 'title' => '有料', 'status' => VideoStatus::Ready, 'visibility' => 'public', 'required_entitlement' => 'premium', 'duration_seconds' => 60]);
    $this->clientApp->forceFill(['video_provider_settings' => []])->save();
    config(['eevee.video.fake' => true]);
    $api = "/api/v1/apps/{$this->clientApp->app_key}";

    $this->getJson("{$api}/videos")->assertJsonPath('data.0.playable', false)->assertJsonPath('data.0.locked_reason', 'login_required');
    $this->withToken($this->token)->getJson("{$api}/videos")->assertJsonPath('data.0.locked_reason', 'entitlement_required');
    $this->withToken($this->token)->postJson("{$api}/videos/{$video->id}/play")->assertForbidden()->assertJsonPath('error.code', 'entitlement_required');

    $meta = ['app_id' => (string) $this->clientApp->id, 'app_user_id' => (string) $this->user->id, 'billing_plan_id' => (string) $this->plan->id, 'entitlement' => 'premium'];
    Http::fake(['api.stripe.com/v1/subscriptions/sub_1' => Http::sequence()
        ->push(stripeSubscription('active', $meta, now()->addMonth()->timestamp))
        ->push(stripeSubscription('canceled', $meta, now()->subMinute()->timestamp))]);
    // ペイロードの中身ではなく、Stripe から取得した最新の状態を使う
    [$body, $server] = stripeWebhook(['id' => 'evt_1', 'type' => 'customer.subscription.created', 'data' => ['object' => ['id' => 'sub_1', 'status' => 'incomplete']]]);
    $this->call('POST', '/webhooks/stripe', [], [], [], $server, $body)->assertOk()->assertJsonPath('data.updated', true);

    $sub = Subscription::withoutGlobalScopes()->sole();
    expect($sub->status)->toBe('active')->and($sub->app_user_id)->toBe($this->user->id)->and($sub->billing_plan_id)->toBe($this->plan->id);

    $this->user->refresh();
    $this->withToken($this->token)->getJson("{$api}/me/entitlements")->assertOk()->assertJsonPath('data.entitlements', ['premium']);
    $this->withToken($this->token)->getJson("{$api}/videos")->assertJsonPath('data.0.playable', true)->assertJsonPath('data.0.locked_reason', null);
    $this->withToken($this->token)->postJson("{$api}/videos/{$video->id}/play")->assertCreated();
    $this->withToken($this->token)->getJson("{$api}/billing/plans")->assertJsonPath('data.0.subscribed', true);
    $this->withToken($this->token)->postJson("{$api}/billing/checkout", ['plan_id' => $this->plan->id])->assertStatus(409);

    // 解約（同じ購読 ID で更新される）
    [$body, $server] = stripeWebhook(['id' => 'evt_2', 'type' => 'customer.subscription.deleted', 'data' => ['object' => ['id' => 'sub_1']]]);
    $this->call('POST', '/webhooks/stripe', [], [], [], $server, $body)->assertOk();
    expect(Subscription::withoutGlobalScopes()->count())->toBe(1)->and($this->user->fresh()->entitlements())->toBe([]);
});

it('rejects webhooks with a bad or old signature', function () {
    [$body, $server] = stripeWebhook(['type' => 'customer.subscription.updated', 'data' => ['object' => ['id' => 'sub_1']]], 'whsec_wrong');
    $this->call('POST', '/webhooks/stripe', [], [], [], $server, $body)->assertUnauthorized();

    $old = time() - 600;
    expect(StripeClient::verifySignature('{}', "t={$old},v1=".hash_hmac('sha256', "{$old}.{}", 'whsec_test'), 'whsec_test'))->toBeFalse();
    Http::assertNothingSent();
});

it('ignores subscriptions that were not created by this system', function () {
    Http::fake(['api.stripe.com/v1/subscriptions/sub_1' => Http::response(stripeSubscription('active', [], now()->addMonth()->timestamp))]);
    [$body, $server] = stripeWebhook(['type' => 'customer.subscription.updated', 'data' => ['object' => ['id' => 'sub_1']]]);
    $this->call('POST', '/webhooks/stripe', [], [], [], $server, $body)->assertOk();
    expect(Subscription::withoutGlobalScopes()->count())->toBe(0);
});

it('updates the connected account status from account.updated', function () {
    $this->org->update(['stripe_charges_enabled' => false]);
    [$body, $server] = stripeWebhook(['type' => 'account.updated', 'data' => ['object' => ['id' => 'acct_org', 'charges_enabled' => true, 'details_submitted' => true]]]);
    $this->call('POST', '/webhooks/stripe', [], [], [], $server, $body)->assertOk()->assertJsonPath('data.updated', true);
    expect($this->org->fresh()->stripe_charges_enabled)->toBeTrue();
});

it('targets subscribers with the entitlement segment field', function () {
    $free = AppUser::factory()->for($this->clientApp)->create(['name' => 'free']);
    $this->user->update(['name' => 'paid']);
    Device::factory()->for($this->clientApp)->create(['app_user_id' => $this->user->id]);
    Device::factory()->for($this->clientApp)->create(['app_user_id' => $free->id]);
    Device::factory()->for($this->clientApp)->create(['app_user_id' => null]);
    Subscription::withoutGlobalScopes()->create(['app_id' => $this->clientApp->id, 'app_user_id' => $this->user->id, 'provider_subscription_id' => 'sub_a', 'status' => 'active', 'entitlement' => 'premium', 'current_period_end' => now()->addDays(3)]);
    Subscription::withoutGlobalScopes()->create(['app_id' => $this->clientApp->id, 'app_user_id' => $free->id, 'provider_subscription_id' => 'sub_b', 'status' => 'canceled', 'entitlement' => 'premium', 'current_period_end' => now()->subDay()]);

    $names = fn (array $c) => app(SegmentQuery::class)->pushableDevices($this->clientApp, $c)->select('u.name')->get()->map(fn ($r) => $r->name ?? 'anon')->sort()->values()->all();
    expect($names(['field' => 'entitlement.premium', 'operator' => 'eq', 'value' => true]))->toBe(['paid'])
        ->and($names(['field' => 'entitlement.premium', 'operator' => 'eq', 'value' => false]))->toBe(['anon', 'free']);
});

it('renders the billing screens', function () {
    Video::withoutGlobalScopes()->create(['app_id' => $this->clientApp->id, 'provider' => 'cloudflare_stream', 'provider_video_id' => 'uid', 'title' => 'v', 'status' => VideoStatus::Ready, 'visibility' => 'public', 'required_entitlement' => 'premium']);
    Subscription::withoutGlobalScopes()->create(['app_id' => $this->clientApp->id, 'app_user_id' => $this->user->id, 'billing_plan_id' => $this->plan->id, 'provider_subscription_id' => 'sub_a', 'status' => 'active', 'entitlement' => 'premium', 'current_period_end' => now()->addDays(3)]);
    actingAsOrgMember($this->org, OrgRole::Owner);
    $this->get('/acme/billing')->assertOk()->assertSee('決済を受け付けられます')->assertSee('10%');
    $this->get("/acme/apps/{$this->clientApp->id}/billing")->assertOk()->assertSee('500円 / 月')->assertSee('u@example.com');
    $this->get("/acme/apps/{$this->clientApp->id}/videos")->assertOk()->assertSee('premium');
    $this->get("/acme/apps/{$this->clientApp->id}/segments/create")->assertOk()->assertSee('entitlement.premium');
    $this->get('/billing/'.$this->clientApp->app_key.'/complete')->assertOk()->assertSee('お手続きが完了しました');
});
