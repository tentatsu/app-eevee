<?php

namespace App\Http\Controllers\Api;

use App\Enums\Platform;
use App\Enums\PushPermission;
use App\Models\Device;
use App\Models\NotificationCategory;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * @tags 端末
 */
class DeviceController extends ApiController
{
    /**
     * 端末の登録・更新
     *
     * `installation_id`（アプリが生成して保存しておく UUID）をキーに登録・更新する。アプリの起動時と FCM トークンが変わったときに呼ぶ。
     * ユーザーのトークン（Bearer）を付けて呼ぶと、端末をそのユーザーに紐づける。ログインしていなくても登録できる。
     *
     * @unauthenticated
     */
    public function upsert(Request $request): JsonResponse
    {
        $app = $this->currentApp();
        $data = $request->validate([
            'installation_id' => ['required', 'uuid'],
            'platform' => ['required', Rule::enum(Platform::class)],
            'fcm_token' => ['nullable', 'string', 'max:512'],
            'app_version' => ['nullable', 'string', 'max:32'],
            'os_version' => ['nullable', 'string', 'max:32'],
            'locale' => ['nullable', 'string', 'max:32'],
            'timezone' => ['nullable', 'timezone:all'],
            'push_permission' => ['nullable', Rule::enum(PushPermission::class)],
        ]);
        $user = $this->optionalAppUser($request);

        $device = DB::transaction(function () use ($app, $data, $user) {
            $device = Device::query()->lockForUpdate()->firstOrNew([
                'app_id' => $app->id,
                'installation_id' => strtolower($data['installation_id']),
            ]);
            $token = array_key_exists('fcm_token', $data) ? $data['fcm_token'] : $device->fcm_token;

            if ($token !== null && $token !== $device->fcm_token) {
                // 同じトークンを持つ古い端末（再インストール等）からはトークンを外し、二重送信を防ぐ
                Device::query()->where('fcm_token', $token)->when($device->exists, fn ($q) => $q->whereKeyNot($device->id))
                    ->update(['fcm_token' => null, 'invalidated_at' => now(), 'invalidated_reason' => 'token_moved']);
                $device->invalidated_at = null;
                $device->invalidated_reason = null;
            }

            $device->fill([
                'platform' => $data['platform'],
                'fcm_token' => $token,
                'app_version' => $data['app_version'] ?? $device->app_version,
                'os_version' => $data['os_version'] ?? $device->os_version,
                'locale' => $data['locale'] ?? $device->locale,
                'timezone' => $data['timezone'] ?? $device->timezone,
                'push_permission' => $data['push_permission'] ?? $device->push_permission ?? PushPermission::NotDetermined,
                'last_active_at' => now(),
            ]);
            if ($user !== null) {
                $device->app_user_id = $user->id;
            }
            $device->save();

            return $device;
        });

        return ApiResponse::ok($device->toApiArray(), $device->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * 通知カテゴリの一覧
     *
     * @unauthenticated
     */
    public function categories(): JsonResponse
    {
        $items = NotificationCategory::query()->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (NotificationCategory $c) => ['key' => $c->key, 'label' => $c->label, 'default_enabled' => $c->default_enabled])
            ->values();

        return ApiResponse::ok($items);
    }
}
