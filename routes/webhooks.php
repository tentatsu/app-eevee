<?php

use App\Http\Controllers\Webhooks\CloudflareWebhookController;
use App\Http\Controllers\Webhooks\SharedCloudflareWebhookController;
use App\Http\Controllers\Webhooks\StripeWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Webhook（IP制限なし・CSRFなし・セッションなし）。署名で検証する。
*/

Route::prefix('webhooks')->name('webhooks.')->group(function () {
    // 共用の Cloudflare アカウント（アカウントに1つの Webhook）。{token} は推測できないランダム値
    Route::post('cloudflare/shared/{token}', SharedCloudflareWebhookController::class)->middleware('throttle:600,1')->name('cloudflare.shared');
    Route::post('stripe', StripeWebhookController::class)->middleware('throttle:600,1')->name('stripe');
    Route::post('cloudflare/{app_key}', CloudflareWebhookController::class)->middleware(['api.app', 'throttle:600,1'])->name('cloudflare');
});
