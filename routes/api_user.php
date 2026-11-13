<?php

use App\Http\Controllers\Api;
use Illuminate\Support\Facades\Route;

// ログインが必要な API（routes/api.php から読み込む）

Route::get('notices/unread-count', [Api\NoticeController::class, 'unreadCount'])->name('notices.unread');
Route::post('notices/{notice}/read', [Api\NoticeController::class, 'markRead'])->whereNumber('notice')->name('notices.read');

Route::get('me/entitlements', [Api\BillingController::class, 'entitlements'])->name('me.entitlements');
Route::post('billing/checkout', [Api\BillingController::class, 'checkout'])->middleware('throttle:app-auth')->name('billing.checkout');
Route::post('billing/portal', [Api\BillingController::class, 'portal'])->middleware('throttle:app-auth')->name('billing.portal');
