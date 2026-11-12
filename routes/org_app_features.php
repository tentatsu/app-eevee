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
