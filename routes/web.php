<?php

use App\Http\Controllers\Org;
use App\Http\Controllers\System;
use App\Models\Organization;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| システム管理画面 /system/...
|--------------------------------------------------------------------------
*/
Route::prefix('system')->name('system.')->middleware('system.ip')->group(function () {
    Route::get('login', [System\AuthController::class, 'showLogin'])->name('login');
    Route::post('login', [System\AuthController::class, 'login'])->middleware('throttle:admin-login');

    Route::middleware('auth:system')->group(function () {
        Route::post('logout', [System\AuthController::class, 'logout'])->name('logout');
        Route::get('/', [System\OrganizationController::class, 'index'])->name('dashboard');

        Route::get('organizations/create', [System\OrganizationController::class, 'create'])->name('organizations.create');
        Route::post('organizations', [System\OrganizationController::class, 'store'])->name('organizations.store');
        Route::get('organizations/{organization}', [System\OrganizationController::class, 'show'])->name('organizations.show');
        Route::get('organizations/{organization}/edit', [System\OrganizationController::class, 'edit'])->name('organizations.edit');
        Route::put('organizations/{organization}', [System\OrganizationController::class, 'update'])->name('organizations.update');
        Route::delete('organizations/{organization}', [System\OrganizationController::class, 'destroy'])->name('organizations.destroy');
        Route::post('organizations/{organization}/suspend', [System\OrganizationController::class, 'suspend'])->name('organizations.suspend');
        Route::post('organizations/{organization}/resume', [System\OrganizationController::class, 'resume'])->name('organizations.resume');
        Route::post('organizations/{organization}/invitations', [System\OrganizationController::class, 'invite'])->name('organizations.invite');
        Route::post('organizations/{organization}/invitations/{invitation}/resend', [System\OrganizationController::class, 'resendInvitation'])->whereNumber('invitation')->name('organizations.invitations.resend');
        Route::delete('organizations/{organization}/invitations/{invitation}', [System\OrganizationController::class, 'revokeInvitation'])->whereNumber('invitation')->name('organizations.invitations.destroy');
        Route::delete('organizations/{organization}/ip-allowlist', [System\OrganizationController::class, 'clearIpAllowlist'])->name('organizations.ip-allowlist.clear');

        Route::get('ip-allowlist', [System\IpAllowlistController::class, 'edit'])->name('ip-allowlist.edit');
        Route::put('ip-allowlist', [System\IpAllowlistController::class, 'update'])->name('ip-allowlist.update');
        Route::get('audit-logs', [System\AuditLogController::class, 'index'])->name('audit-logs');
        Route::get('usage', [System\UsageController::class, 'index'])->name('usage');

        Route::resource('provider-accounts', System\ProviderAccountController::class)->except(['show']);
        Route::post('provider-accounts/{provider_account}/provision', [System\ProviderAccountController::class, 'provision'])->name('provider-accounts.provision');
        Route::put('apps/{app}/video-account', [System\ProviderAccountController::class, 'assign'])->whereNumber('app')->name('apps.video-account');
    });
});

Route::view('/', 'home')->name('home');

/*
|--------------------------------------------------------------------------
| 組織の管理画面 /{org_slug}/...
|--------------------------------------------------------------------------
| 予約語は slug に使えないため、/system や /api などとは衝突しない。
*/
$reserved = implode('|', array_map(fn ($s) => preg_quote($s, '#'), Organization::RESERVED_SLUGS));

Route::prefix('{organization}')
    ->where(['organization' => '(?!(?:'.$reserved.')$)'.Organization::SLUG_PATTERN])
    ->middleware(['org', 'org.ip'])
    ->name('org.')
    ->group(function () {
        Route::get('login', [Org\AuthController::class, 'showLogin'])->name('login');
        Route::post('login', [Org\AuthController::class, 'login'])->middleware('throttle:admin-login');
        Route::get('invitations/{token}', [Org\InvitationController::class, 'show'])->name('invitations.show');
        Route::post('invitations/{token}', [Org\InvitationController::class, 'accept'])->name('invitations.accept');

        Route::middleware(['auth:admin', 'org.member'])->group(function () {
            Route::post('logout', [Org\AuthController::class, 'logout'])->name('logout');
            Route::get('/', Org\DashboardController::class)->name('dashboard');

            Route::middleware('can:manage-organization')->group(function () {
                Route::get('members', [Org\MemberController::class, 'index'])->name('members.index');
                Route::post('members/invitations', [Org\MemberController::class, 'invite'])->name('members.invite');
                Route::post('members/invitations/{invitation}/resend', [Org\MemberController::class, 'resendInvitation'])->name('members.invitations.resend');
                Route::delete('members/invitations/{invitation}', [Org\MemberController::class, 'revokeInvitation'])->name('members.invitations.destroy');
                Route::put('members/{member}', [Org\MemberController::class, 'update'])->name('members.update');
                Route::delete('members/{member}', [Org\MemberController::class, 'destroy'])->name('members.destroy');

                Route::get('ip-allowlist', [Org\IpAllowlistController::class, 'edit'])->name('ip-allowlist.edit');
                Route::put('ip-allowlist', [Org\IpAllowlistController::class, 'update'])->name('ip-allowlist.update');
            });

            Route::get('audit-logs', [Org\AuditLogController::class, 'index'])->middleware('can:view-audit-logs')->name('audit-logs');

            require __DIR__.'/org_apps.php';
        });
    });
