<?php

use App\Enums\OrgRole;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Services\Audit\AuditLogger;

it('records who did what, when, in which organization and from which IP', function () {
    $org = Organization::factory()->create(['slug' => 'acme']);
    $user = actingAsOrgMember($org, OrgRole::Owner);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->put('/acme/ip-allowlist', ['entries' => '203.0.113.0/24']);

    $log = AuditLog::query()->where('action', 'ip_allowlist.updated')->firstOrFail();
    expect($log->organization_id)->toBe($org->id)
        ->and($log->actor_type)->toBe('admin_user')
        ->and($log->actor_id)->toBe($user->id)
        ->and($log->ip_address)->toBe('203.0.113.7')
        ->and($log->created_at)->not->toBeNull();
});

it('shows only the organization own logs', function () {
    $orgA = Organization::factory()->create(['slug' => 'org-a']);
    $orgB = Organization::factory()->create(['slug' => 'org-b']);
    AuditLog::query()->forceCreate(['organization_id' => $orgA->id, 'actor_type' => 'admin_user', 'action' => 'x.a', 'summary' => 'LOG-OF-A']);
    AuditLog::query()->forceCreate(['organization_id' => $orgB->id, 'actor_type' => 'admin_user', 'action' => 'x.b', 'summary' => 'LOG-OF-B']);

    actingAsOrgMember($orgA, OrgRole::Admin);
    $this->get('/org-a/audit-logs')->assertOk()->assertSee('LOG-OF-A')->assertDontSee('LOG-OF-B');
});

it('masks secrets in recorded changes', function () {
    $masked = AuditLogger::mask([
        'name' => 'x',
        'app_secret' => 'abc',
        'settings' => ['api_token' => 't', 'signing_private_key' => 'k', 'account_id' => 'acc'],
        'password' => 'p',
    ]);
    expect($masked)->toBe([
        'name' => 'x',
        'app_secret' => '***',
        'settings' => ['api_token' => '***', 'signing_private_key' => '***', 'account_id' => 'acc'],
        'password' => '***',
    ]);
});
