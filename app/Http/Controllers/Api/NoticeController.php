<?php

namespace App\Http\Controllers\Api;

use App\Models\AppUser;
use App\Models\Notice;
use App\Models\NoticeRead;
use App\Services\Notices\NoticeVisibility;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @tags お知らせ
 */
class NoticeController extends ApiController
{
    public function __construct(private readonly NoticeVisibility $visibility) {}

    /**
     * お知らせの一覧
     *
     * 公開中で、そのユーザーが対象のお知らせだけを返す（ピン留め → 新しい順）。トークンを付けると、セグメント向けのお知らせと既読状態（`is_read`）も含む。
     *
     * @unauthenticated
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $user = $this->optionalAppUser($request);
        $ids = $this->visibility->visibleIds($this->currentApp(), $user);
        $page = $this->visibility->query($ids)->paginate($request->integer('per_page', 20));

        $readIds = $this->readIds($user, collect($page->items())->pluck('id')->all());

        return ApiResponse::paginated($page, fn (Notice $n) => $this->toArray($n, $readIds, withBody: false));
    }

    /**
     * お知らせの詳細
     *
     * 本文は Markdown（`body`）と、変換済みの HTML（`body_html`）の両方を返す。
     *
     * @unauthenticated
     */
    public function show(Request $request, int $notice): JsonResponse
    {
        $user = $this->optionalAppUser($request);
        $model = $this->findVisible($user, $notice);
        if ($model === null) {
            return ApiResponse::error('notice_not_found', 'The notice was not found.', 404);
        }

        return ApiResponse::ok($this->toArray($model, $this->readIds($user, [$model->id]), withBody: true));
    }

    /**
     * 既読にする
     */
    public function markRead(Request $request, int $notice): JsonResponse
    {
        $user = $this->appUser($request);
        $model = $this->findVisible($user, $notice);
        if ($model === null) {
            return ApiResponse::error('notice_not_found', 'The notice was not found.', 404);
        }
        DB::table('notice_reads')->insertOrIgnore(['notice_id' => $model->id, 'app_user_id' => $user->id, 'read_at' => now()]);

        return ApiResponse::ok(['read' => true, 'unread_count' => $this->countUnread($user)]);
    }

    /**
     * 未読数
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return ApiResponse::ok(['unread_count' => $this->countUnread($this->appUser($request))]);
    }

    private function countUnread(AppUser $user): int
    {
        $ids = $this->visibility->visibleIds($this->currentApp(), $user);
        if ($ids === []) {
            return 0;
        }
        $read = NoticeRead::query()->where('app_user_id', $user->id)->whereIn('notice_id', $ids)->count();

        return count($ids) - $read;
    }

    private function findVisible(?AppUser $user, int $id): ?Notice
    {
        $ids = $this->visibility->visibleIds($this->currentApp(), $user);

        return in_array($id, $ids, true) ? Notice::query()->find($id) : null;
    }

    /**
     * @param  list<int>  $noticeIds
     * @return array<int, true>
     */
    private function readIds(?AppUser $user, array $noticeIds): array
    {
        if ($user === null || $noticeIds === []) {
            return [];
        }

        $ids = NoticeRead::query()->where('app_user_id', $user->id)->whereIn('notice_id', $noticeIds)->pluck('notice_id')->all();

        return array_fill_keys(array_map('intval', $ids), true);
    }

    /**
     * @param  array<int, true>  $readIds
     * @return array<string, mixed>
     */
    private function toArray(Notice $n, array $readIds, bool $withBody): array
    {
        $out = [
            'id' => $n->id,
            'title' => $n->title,
            'thumbnail_url' => $n->thumbnail_url,
            'is_pinned' => $n->is_pinned,
            'published_at' => ($n->publish_start_at ?? $n->created_at)?->toIso8601ZuluString(),
            'publish_end_at' => $n->publish_end_at?->toIso8601ZuluString(),
            'is_read' => isset($readIds[$n->id]),
        ];
        if ($withBody) {
            $out['body'] = $n->body;
            $out['body_html'] = $n->bodyHtml();
        }

        return $out;
    }
}
