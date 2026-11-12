<?php

namespace App\Services\Push;

use App\Enums\CampaignTarget;
use App\Models\App;
use App\Models\NotificationCategory;
use App\Models\PushCampaign;
use App\Models\Segment;
use App\Services\Segments\SegmentQuery;
use Illuminate\Database\Query\Builder;

/**
 * キャンペーンの対象（全員 / セグメント / ユーザーID指定 / テスト端末）から、送信先の端末を抽出するクエリを作る。
 * 通知カテゴリが指定されていれば、そのカテゴリをオフにしているユーザーの端末は除く。
 */
class CampaignTargetResolver
{
    public function __construct(private readonly SegmentQuery $segments) {}

    public function query(App $app, PushCampaign $campaign): Builder
    {
        $conditions = null;
        if ($campaign->target_type === CampaignTarget::Segment) {
            $segment = Segment::withoutGlobalScopes()->where('app_id', $app->id)->find($campaign->segment_id);
            // セグメントが削除されていたら誰にも送らない
            $conditions = $segment !== null ? $segment->conditions : ['op' => 'or', 'conditions' => []];
        }

        $query = $this->segments->pushableDevices($app, $conditions);

        match ($campaign->target_type) {
            CampaignTarget::Users => $query->whereIn('d.app_user_id', array_map('intval', $campaign->target_user_ids ?? [0])),
            CampaignTarget::TestDevices => $query->where('d.is_test_device', true),
            default => null,
        };

        if ($campaign->notification_category_id !== null) {
            $category = NotificationCategory::withoutGlobalScopes()->where('app_id', $app->id)->find($campaign->notification_category_id);
            if ($category !== null) {
                $query->whereRaw(
                    'COALESCE(JSON_UNQUOTE(JSON_EXTRACT(u.notification_preferences, ?)), ?) = ?',
                    ['$."'.$category->key.'"', $category->default_enabled ? 'true' : 'false', 'true'],
                );
            }
        }

        return $query;
    }

    /**
     * @return array{devices: int, users: int}
     */
    public function count(App $app, PushCampaign $campaign): array
    {
        $row = $this->query($app, $campaign)->selectRaw('COUNT(*) as devices, COUNT(DISTINCT d.app_user_id) as users')->first();

        return ['devices' => (int) ($row->devices ?? 0), 'users' => (int) ($row->users ?? 0)];
    }
}
