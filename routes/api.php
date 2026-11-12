<?php

use App\Http\Controllers\Api;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| アプリ向けAPI /api/v1/apps/{app_key}/...
|--------------------------------------------------------------------------
| IP制限はかけない。未ログインで使えるものは app_key だけで呼べ、
| ログインが必要なものは Sanctum のトークン（Authorization: Bearer）を使う。
*/
Route::prefix('v1/apps/{app_key}')
    ->middleware(['api.app', 'throttle:app-api'])
    ->name('api.')
    ->group(function () {
        // 認証（builtin）
        Route::middleware('throttle:app-auth')->group(function () {
            Route::post('auth/register', [Api\AuthController::class, 'register'])->name('auth.register');
            Route::post('auth/login', [Api\AuthController::class, 'login'])->name('auth.login');
            Route::post('auth/password/forgot', [Api\AuthController::class, 'forgotPassword'])->name('auth.password.forgot');
            Route::post('auth/password/reset', [Api\AuthController::class, 'resetPassword'])->name('auth.password.reset');
        });

        // 未ログインでも使える（トークンがあれば検証してユーザーを特定する）
        Route::middleware('api.user:optional')->group(function () {
            Route::post('devices', [Api\DeviceController::class, 'upsert'])->name('devices.upsert');
            Route::get('notification-categories', [Api\DeviceController::class, 'categories'])->name('categories.index');

            require __DIR__.'/api_public.php';
        });

        // ログインが必要
        Route::middleware('api.user')->group(function () {
            Route::post('auth/logout', [Api\AuthController::class, 'logout'])->name('auth.logout');
            Route::get('me', [Api\MeController::class, 'show'])->name('me.show');
            Route::patch('me/attributes', [Api\MeController::class, 'updateAttributes'])->name('me.attributes');
            Route::get('me/notification-preferences', [Api\MeController::class, 'notificationPreferences'])->name('me.preferences');
            Route::put('me/notification-preferences', [Api\MeController::class, 'updateNotificationPreferences'])->name('me.preferences.update');
            Route::post('me/devices/link', [Api\MeController::class, 'linkDevice'])->name('me.devices.link');
            Route::post('me/devices/unlink', [Api\MeController::class, 'unlinkDevice'])->name('me.devices.unlink');

            require __DIR__.'/api_user.php';
        });
    });

/*
|--------------------------------------------------------------------------
| サーバー間API /api/v1/server/apps/{app_key}/...
|--------------------------------------------------------------------------
| 顧客のバックエンドから Authorization: Bearer {app_secret} で呼ぶ。
*/
Route::prefix('v1/server/apps/{app_key}')
    ->middleware(['api.app', 'api.secret', 'throttle:server-api'])
    ->name('api.server.')
    ->group(function () {
        Route::post('users/attributes', [Api\Server\UserController::class, 'bulkAttributes'])->name('users.attributes');
        Route::put('users/{external_user_id}', [Api\Server\UserController::class, 'upsert'])->name('users.upsert');
        Route::get('users/{external_user_id}', [Api\Server\UserController::class, 'show'])->name('users.show');
        Route::delete('users/{external_user_id}', [Api\Server\UserController::class, 'destroy'])->name('users.destroy');
        Route::post('users/{external_user_id}/tokens', [Api\Server\UserController::class, 'issueToken'])->name('users.tokens');

        require __DIR__.'/api_server.php';
    });
