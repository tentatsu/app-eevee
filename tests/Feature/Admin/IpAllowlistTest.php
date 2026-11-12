<?php

use App\Enums\OrgRole;
use App\Models\App;
use App\Models\AuditLog;
use App\Models\IpAllowEntry;
use App\Models\Organization;
use App\Support\Ip\IpRange;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

beforeEach(function () {
    $this->org = Organization::factory()->create(['slug' => 'acme']);
});

function allow(Organization $org, string ...$cidrs): void
{
    foreach ($cidrs as $cidr) {
        IpAllowEntry::query()->create(['organization_id' => $org->id, 'cidr' => IpRange::normalize($cidr)]);
    }
}

function fromIp(string $ip): TestCase
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip]);
}

it('does not restrict when the list is empty', function () {
    fromIp('198.51.100.1')->get('/acme/login')->assertOk();
});

it('restricts the login page and admin pages by IPv4 CIDR', function () {
    allow($this->org, '203.0.113.0/24');
    fromIp('203.0.113.77')->get('/acme/login')->assertOk();
    fromIp('203.0.114.1')->get('/acme/login')->assertForbidden();
    fromIp('203.0.114.1')->post('/acme/login', ['email' => 'a@example.com', 'password' => 'x'])->assertForbidden();

    actingAsOrgMember($this->org);
    fromIp('203.0.114.1')->get('/acme')->assertForbidden();
    fromIp('203.0.113.1')->get('/acme')->assertOk();
});

it('restricts by a single IPv4 address', function () {
    allow($this->org, '192.0.2.10');
    fromIp('192.0.2.10')->get('/acme/login')->assertOk();
    fromIp('192.0.2.11')->get('/acme/login')->assertForbidden();
});

it('restricts by IPv6 address and CIDR', function () {
    allow($this->org, '2001:db8:abcd::/48', '2001:db8:ffff::1');
    fromIp('2001:db8:abcd:12::5')->get('/acme/login')->assertOk();
    fromIp('2001:0db8:ffff:0000:0000:0000:0000:0001')->get('/acme/login')->assertOk();
    fromIp('2001:db8:abce::1')->get('/acme/login')->assertForbidden();
    fromIp('203.0.113.1')->get('/acme/login')->assertForbidden();
});

it('only affects the organization that set the list', function () {
    $other = Organization::factory()->create(['slug' => 'other']);
    allow($this->org, '203.0.113.0/24');
    fromIp('198.51.100.1')->get('/other/login')->assertOk();
});

it('logs denied access', function () {
    Log::shouldReceive('channel')->with('security')->andReturnSelf();
    Log::shouldReceive('warning')->once()->withArgs(fn ($msg, $ctx) => $ctx['ip'] === '198.51.100.9' && $ctx['organization'] === 'acme');
    allow($this->org, '203.0.113.0/24');
    fromIp('198.51.100.9')->get('/acme/login')->assertForbidden();
});

it('does not apply to the app API', function () {
    allow($this->org, '203.0.113.0/24');
    $app = App::factory()->for($this->org)->create();
    fromIp('198.51.100.1')->getJson("/api/v1/apps/{$app->app_key}/notification-categories")->assertOk();
    $secret = $app->regenerateSecret();
    fromIp('198.51.100.1')->withToken($secret)->getJson("/api/v1/server/apps/{$app->app_key}/users/nobody")->assertNotFound()->assertJsonPath('error.code', 'user_not_found');
});

it('uses X-Forwarded-For only from trusted proxies', function () {
    allow($this->org, '203.0.113.0/24');

    // 信頼していないプロキシからのヘッダーは無視される
    config(['eevee.trusted_proxies' => '']);
    fromIp('10.0.0.5')->withHeader('X-Forwarded-For', '203.0.113.9')->get('/acme/login')->assertForbidden();

    // 信頼するプロキシ（例: ロードバランサー）からのヘッダーは採用される
    config(['eevee.trusted_proxies' => '10.0.0.0/8']);
    fromIp('10.0.0.5')->withHeader('X-Forwarded-For', '203.0.113.9')->get('/acme/login')->assertOk();
    fromIp('10.0.0.5')->withHeader('X-Forwarded-For', '198.51.100.1')->get('/acme/login')->assertForbidden();

    // 多段（クライアント, Cloudflare）でも信頼するプロキシを除いた最初のIPを使う
    config(['eevee.trusted_proxies' => '10.0.0.0/8,173.245.48.0/20']);
    fromIp('10.0.0.5')->withHeader('X-Forwarded-For', '203.0.113.9, 173.245.48.10')->get('/acme/login')->assertOk();
});

it('saves the allowlist and warns before locking yourself out', function () {
    actingAsOrgMember($this->org, OrgRole::Owner);

    fromIp('198.51.100.1')->put('/acme/ip-allowlist', ['entries' => "203.0.113.0/24 # 本社\n2001:db8::/32"])
        ->assertRedirect()->assertSessionHas('lockout_warning');
    expect(IpAllowEntry::query()->count())->toBe(0);

    fromIp('198.51.100.1')->put('/acme/ip-allowlist', ['entries' => "203.0.113.0/24 # 本社\n2001:db8::/32", 'confirm_lockout' => '1'])
        ->assertRedirect('/acme/ip-allowlist');
    expect(IpAllowEntry::query()->pluck('cidr')->all())->toBe(['203.0.113.0/24', '2001:db8::/32']);
    expect(IpAllowEntry::query()->first()->note)->toBe('本社');
    expect(AuditLog::query()->where('action', 'ip_allowlist.updated')->exists())->toBeTrue();

    // 締め出された
    fromIp('198.51.100.1')->get('/acme')->assertForbidden();
});

it('saves without a warning when the current IP is included', function () {
    actingAsOrgMember($this->org, OrgRole::Owner);
    fromIp('203.0.113.5')->put('/acme/ip-allowlist', ['entries' => '203.0.113.0/24'])->assertRedirect('/acme/ip-allowlist')->assertSessionMissing('lockout_warning');
    expect(IpAllowEntry::query()->count())->toBe(1);
});

it('validates entries', function () {
    actingAsOrgMember($this->org, OrgRole::Owner);
    $this->put('/acme/ip-allowlist', ['entries' => "203.0.113.0/33\nnot-an-ip"])->assertSessionHasErrors('entries');
});

it('lets the system admin clear any organization allowlist', function () {
    allow($this->org, '203.0.113.0/24');
    actingAsSystemAdmin();
    $this->delete('/system/organizations/acme/ip-allowlist')->assertRedirect();
    expect(IpAllowEntry::query()->count())->toBe(0);
    expect(AuditLog::query()->where('action', 'ip_allowlist.cleared')->value('actor_type'))->toBe('system_admin');
});

it('provides an artisan command for emergency unlock', function () {
    allow($this->org, '203.0.113.0/24');
    $this->artisan('eevee:ip-allowlist:clear', ['org_slug' => 'acme'])->assertSuccessful();
    expect(IpAllowEntry::query()->count())->toBe(0);
    fromIp('198.51.100.1')->get('/acme/login')->assertOk();

    $this->artisan('eevee:ip-allowlist:clear', ['org_slug' => 'nope'])->assertFailed();
});

it('restricts the system admin panel with env and DB allowlists', function () {
    fromIp('198.51.100.1')->get('/system/login')->assertOk();

    config(['eevee.system_ip_allowlist' => '192.0.2.0/24, 2001:db8::/32']);
    fromIp('198.51.100.1')->get('/system/login')->assertForbidden();
    fromIp('192.0.2.50')->get('/system/login')->assertOk();
    fromIp('2001:db8::5')->get('/system/login')->assertOk();

    IpAllowEntry::query()->create(['organization_id' => null, 'cidr' => '198.51.100.0/24']);
    fromIp('198.51.100.1')->get('/system/login')->assertOk();

    // システムの許可リストは組織の管理画面には影響しない
    fromIp('203.0.113.1')->get('/acme/login')->assertOk();

    $this->artisan('eevee:ip-allowlist:clear', ['--system' => true])->assertSuccessful();
    expect(IpAllowEntry::query()->whereNull('organization_id')->count())->toBe(0);
});

it('warns the system admin before locking out of /system', function () {
    actingAsSystemAdmin();
    fromIp('198.51.100.1')->put('/system/ip-allowlist', ['entries' => '192.0.2.0/24'])->assertSessionHas('lockout_warning');
    fromIp('198.51.100.1')->put('/system/ip-allowlist', ['entries' => '192.0.2.0/24', 'confirm_lockout' => 1])->assertRedirect('/system/ip-allowlist');
    fromIp('198.51.100.1')->get('/system')->assertForbidden();
});
