<?php

use App\Http\Controllers\Api;
use Illuminate\Support\Facades\Route;

// 未ログインでも使える API（routes/api.php から読み込む）

Route::post('push/opened', [Api\PushController::class, 'opened'])->name('push.opened');

Route::get('notices', [Api\NoticeController::class, 'index'])->name('notices.index');
Route::get('notices/{notice}', [Api\NoticeController::class, 'show'])->whereNumber('notice')->name('notices.show');
