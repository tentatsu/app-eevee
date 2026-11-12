<?php

namespace App\Http\Controllers\Api;

use App\Models\NotificationCategory;
use App\Services\AppUsers\AppUserTokenIssuer;
use App\Services\AppUsers\AttributeNormalizer;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * @tags 自分（ログインユーザー）
 */
class MeController extends ApiController
{
    /**
     * 自分の情報
     */
    public function show(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->appUser($request)->toApiArray());
    }

    /**
     * 自分の属性を更新
     *
     * アプリから更新が許可された属性（管理画面の「アプリから更新可」）だけを更新できる。`null` を指定したキーは削除する。
     */
    public function updateAttributes(Request $request, AttributeNormalizer $normalizer): JsonResponse
    {
        $user = $this->appUser($request);
        $data = $request->validate(['attributes' => ['required', 'array']]);
        $changes = $normalizer->normalize($this->currentApp(), $data['attributes'], fromApp: true);

        $user->custom_attributes = AttributeNormalizer::merge($user->custom_attributes, $changes);
        $user->save();

        return ApiResponse::ok($user->toApiArray());
    }

    /**
     * 通知カテゴリごとの受信設定を取得
     */
    public function notificationPreferences(Request $request): JsonResponse
    {
        $user = $this->appUser($request);
        $items = NotificationCategory::query()->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (NotificationCategory $c) => [
                'key' => $c->key,
                'label' => $c->label,
                'enabled' => $user->isCategoryEnabled($c),
            ])->values();

        return ApiResponse::ok($items);
    }

    /**
     * 通知カテゴリごとの受信設定を更新
     *
     * 例: `{"preferences": {"campaign": false, "notice": true}}`
     */
    public function updateNotificationPreferences(Request $request): JsonResponse
    {
        $user = $this->appUser($request);
        $data = $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.*' => ['boolean'],
        ]);
        $keys = NotificationCategory::query()->pluck('key')->all();
        $unknown = array_diff(array_keys($data['preferences']), $keys);
        if ($unknown !== []) {
            throw ValidationException::withMessages(['preferences' => 'Unknown categories: '.implode(', ', $unknown)]);
        }

        $prefs = $user->notification_preferences ?? [];
        foreach ($data['preferences'] as $key => $enabled) {
            $prefs[$key] = (bool) $enabled;
        }
        $user->notification_preferences = $prefs;
        $user->save();

        return $this->notificationPreferences($request);
    }

    /**
     * 端末をログインユーザーに紐づける
     *
     * ログイン後に呼ぶ（ログイン API に `installation_id` を渡した場合は不要）。
     */
    public function linkDevice(Request $request, AppUserTokenIssuer $tokens): JsonResponse
    {
        $data = $request->validate(['installation_id' => ['required', 'uuid']]);
        $device = $tokens->linkDevice($this->appUser($request), $data['installation_id']);
        if ($device === null) {
            return ApiResponse::error('device_not_found', 'Register the device first.', 404);
        }

        return ApiResponse::ok($device->toApiArray());
    }

    /**
     * 端末とログインユーザーの紐づけを外す
     */
    public function unlinkDevice(Request $request, AppUserTokenIssuer $tokens): JsonResponse
    {
        $data = $request->validate(['installation_id' => ['required', 'uuid']]);
        $device = $tokens->unlinkDevice($this->appUser($request), $data['installation_id']);

        return ApiResponse::ok(['unlinked' => $device !== null]);
    }
}
