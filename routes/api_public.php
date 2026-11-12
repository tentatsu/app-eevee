<?php

use App\Http\Controllers\Api;
use Illuminate\Support\Facades\Route;

// 未ログインでも使える API（routes/api.php から読み込む）

Route::post('push/opened', [Api\PushController::class, 'opened'])->name('push.opened');

Route::get('notices', [Api\NoticeController::class, 'index'])->name('notices.index');
Route::get('notices/{notice}', [Api\NoticeController::class, 'show'])->whereNumber('notice')->name('notices.show');

Route::get('videos', [Api\VideoController::class, 'index'])->name('videos.index');
Route::get('videos/{video}', [Api\VideoController::class, 'show'])->whereNumber('video')->name('videos.show');
Route::post('videos/{video}/play', [Api\VideoController::class, 'play'])->whereNumber('video')->name('videos.play');
Route::post('playback-sessions/{session}/heartbeat', [Api\VideoController::class, 'heartbeat'])->name('playback.heartbeat');
Route::post('playback-sessions/{session}/stop', [Api\VideoController::class, 'stop'])->name('playback.stop');
