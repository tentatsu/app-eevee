<?php

use App\Http\Controllers\Org\Apps;
use Illuminate\Support\Facades\Route;

/*
| セグメント・プッシュ・お知らせ・動画（routes/org_apps.php から読み込む）
*/

Route::post('segments/preview', [Apps\SegmentController::class, 'preview'])->name('segments.preview');
Route::resource('segments', Apps\SegmentController::class)->except(['show']);

Route::resource('campaigns', Apps\CampaignController::class);
Route::post('campaigns/{campaign}/send', [Apps\CampaignController::class, 'send'])->name('campaigns.send');
Route::post('campaigns/{campaign}/cancel', [Apps\CampaignController::class, 'cancel'])->name('campaigns.cancel');
Route::post('campaigns/{campaign}/test', [Apps\CampaignController::class, 'sendTest'])->name('campaigns.test');

Route::resource('notices', Apps\NoticeController::class)->except(['show']);
Route::post('notices/{notice}/push', [Apps\NoticeController::class, 'push'])->name('notices.push');

Route::resource('videos', Apps\VideoController::class)->except(['show']);
Route::post('videos/{video}/upload-url', [Apps\VideoController::class, 'uploadUrl'])->name('videos.upload-url');
Route::post('videos/{video}/uploaded', [Apps\VideoController::class, 'uploaded'])->name('videos.uploaded');
Route::post('videos/{video}/refresh', [Apps\VideoController::class, 'refresh'])->name('videos.refresh');
