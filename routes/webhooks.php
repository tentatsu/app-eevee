<?php

use App\Http\Controllers\Webhooks\CloudflareWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Webhook（IP制限なし・CSRFなし・セッションなし）。署名で検証する。
*/

Route::prefix('webhooks')->name('webhooks.')->group(function () {
    Route::post('cloudflare/{app_key}', CloudflareWebhookController::class)->middleware(['api.app', 'throttle:600,1'])->name('cloudflare');
});
