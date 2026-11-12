<?php

namespace App\Http\Controllers\Api;

use App\Models\Device;
use App\Models\PushCampaign;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @tags プッシュ通知
 */
class PushController extends ApiController
{
    /**
     * 通知の開封を記録
     *
     * ユーザーが通知をタップしてアプリを開いたときに呼ぶ。`campaign_id` は通知の data に入っている `eevee_campaign_id`。
     * 同じ端末で何度呼んでも 1 回として数える。
     *
     * @unauthenticated
     */
    public function opened(Request $request): JsonResponse
    {
        $data = $request->validate([
            'campaign_id' => ['required', 'integer'],
            'installation_id' => ['required', 'uuid'],
        ]);

        $campaign = PushCampaign::query()->find($data['campaign_id']);
        $device = Device::query()->where('installation_id', strtolower($data['installation_id']))->first();
        if ($campaign === null || $device === null) {
            return ApiResponse::error('delivery_not_found', 'No delivery was found for this campaign and device.', 404);
        }

        $recorded = DB::transaction(function () use ($campaign, $device) {
            $updated = DB::table('push_deliveries')
                ->where('push_campaign_id', $campaign->id)
                ->where('device_id', $device->id)
                ->whereNull('opened_at')
                ->update(['opened_at' => now(), 'updated_at' => now()]);
            if ($updated > 0) {
                PushCampaign::query()->whereKey($campaign->id)->increment('open_count');
            }

            return $updated > 0;
        });

        $exists = $recorded || DB::table('push_deliveries')->where('push_campaign_id', $campaign->id)->where('device_id', $device->id)->exists();
        if (! $exists) {
            return ApiResponse::error('delivery_not_found', 'No delivery was found for this campaign and device.', 404);
        }

        return ApiResponse::ok(['recorded' => $recorded]);
    }
}
