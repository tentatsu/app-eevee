<?php

use App\Http\Controllers\Org\Apps;
use Illuminate\Support\Facades\Route;

/*
| 組織の管理画面: アプリごとの機能 /{org_slug}/apps/{app}/...
| （routes/web.php の組織グループ内から読み込む）
*/

Route::get('apps', [Apps\AppController::class, 'index'])->name('apps.index');
Route::get('apps/create', [Apps\AppController::class, 'create'])->name('apps.create');
Route::post('apps', [Apps\AppController::class, 'store'])->name('apps.store');

Route::prefix('apps/{app}')->where(['app' => '[0-9]+'])->middleware('org.app')->name('apps.')->group(function () {
    Route::get('/', [Apps\AppController::class, 'show'])->name('show');
    Route::get('settings', [Apps\AppController::class, 'settings'])->name('settings');
    Route::put('settings', [Apps\AppController::class, 'update'])->name('update');
    Route::post('secret', [Apps\AppController::class, 'regenerateSecret'])->name('secret');
    Route::put('firebase', [Apps\AppController::class, 'updateFirebase'])->name('firebase');
    Route::put('video-provider', [Apps\AppController::class, 'updateVideoProvider'])->name('video-provider');
    Route::post('video-provider/provision', [Apps\AppController::class, 'provisionVideoProvider'])->name('video-provider.provision');

    Route::get('attributes', [Apps\AttributeDefinitionController::class, 'index'])->name('attributes.index');
    Route::post('attributes', [Apps\AttributeDefinitionController::class, 'store'])->name('attributes.store');
    Route::put('attributes/{attribute}', [Apps\AttributeDefinitionController::class, 'update'])->name('attributes.update');
    Route::delete('attributes/{attribute}', [Apps\AttributeDefinitionController::class, 'destroy'])->name('attributes.destroy');

    Route::get('categories', [Apps\NotificationCategoryController::class, 'index'])->name('categories.index');
    Route::post('categories', [Apps\NotificationCategoryController::class, 'store'])->name('categories.store');
    Route::put('categories/{category}', [Apps\NotificationCategoryController::class, 'update'])->name('categories.update');
    Route::delete('categories/{category}', [Apps\NotificationCategoryController::class, 'destroy'])->name('categories.destroy');

    Route::get('users', [Apps\AppUserController::class, 'index'])->name('users.index');
    Route::get('users/{user}', [Apps\AppUserController::class, 'show'])->name('users.show');

    Route::get('devices', [Apps\DeviceController::class, 'index'])->name('devices.index');
    Route::put('devices/{device}/test', [Apps\DeviceController::class, 'updateTest'])->name('devices.test');

    require __DIR__.'/org_app_features.php';
});
