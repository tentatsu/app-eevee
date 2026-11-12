<?php

namespace App\Services\Notices;

use App\Enums\NoticeTarget;
use App\Models\App;
use App\Models\AppUser;
use App\Models\Notice;
use App\Models\Segment;
use App\Services\Segments\SegmentQuery;
use Illuminate\Database\Eloquent\Builder;

/**
 * ユーザーに表示するお知らせの判定。
 *
 * - 未ログイン: 対象が「全員」の公開中のお知らせ
 * - ログイン中: 上記 ＋ 対象が「セグメント」で、そのユーザーがセグメントに含まれるお知らせ
 */
class NoticeVisibility
{
    public function __construct(private readonly SegmentQuery $segments) {}

    /**
     * ユーザーに表示するお知らせの ID。
     *
     * @return list<int>
     */
    public function visibleIds(App $app, ?AppUser $user): array
    {
        $rows = Notice::query()->visible()->get(['id', 'target_type', 'segment_id']);

        $segmentIds = $rows->where('target_type', NoticeTarget::Segment)->pluck('segment_id')->filter()->unique()->all();
        $matched = [];
        if ($user !== null && $segmentIds !== []) {
            foreach (Segment::query()->whereIn('id', $segmentIds)->get() as $segment) {
                $matched[$segment->id] = $this->segments->userMatches($app, $user, $segment->conditions);
            }
        }

        $ids = [];
        foreach ($rows as $notice) {
            if ($notice->target_type === NoticeTarget::All || ($user !== null && ($matched[$notice->segment_id] ?? false))) {
                $ids[] = $notice->id;
            }
        }

        return $ids;
    }

    /**
     * 一覧の並び順: ピン留め → 公開開始日時の新しい順。
     *
     * @param  list<int>  $ids
     * @return Builder<Notice>
     */
    public function query(array $ids): Builder
    {
        return Notice::query()
            ->whereIn('id', $ids === [] ? [0] : $ids)
            ->orderByDesc('is_pinned')
            ->orderByRaw('COALESCE(publish_start_at, created_at) DESC')
            ->orderByDesc('id');
    }
}
