<?php

namespace App\Services\Segments;

use App\Enums\PushPermission;
use App\Models\App;
use App\Models\AppUser;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * セグメント条件から、対象の端末・ユーザーを抽出するクエリを作る。
 */
class SegmentQuery
{
    public function __construct(private readonly SegmentCompiler $compiler) {}

    /**
     * プッシュ送信できる端末（d）とそのユーザー（u、未ログインなら NULL）。
     *
     * @param  array<array-key, mixed>|null  $conditions
     */
    public function pushableDevices(App $app, ?array $conditions): Builder
    {
        $query = DB::table('devices as d')
            ->leftJoin('app_users as u', 'u.id', '=', 'd.app_user_id')
            ->where('d.app_id', $app->id)
            ->whereNull('d.invalidated_at')
            ->whereNotNull('d.fcm_token')
            ->where('d.push_permission', '!=', PushPermission::Denied->value);

        return $this->compiler->apply($query, $app, $conditions);
    }

    /**
     * 送信前のプレビュー: 対象の端末数とユーザー数（ログインしているユーザーの重複なし件数）。
     *
     * @param  array<array-key, mixed>|null  $conditions
     * @return array{devices: int, users: int, anonymous_devices: int, elapsed_ms: int}
     */
    public function preview(App $app, ?array $conditions): array
    {
        $start = microtime(true);
        $row = $this->pushableDevices($app, $conditions)
            ->selectRaw('COUNT(*) as devices, COUNT(DISTINCT d.app_user_id) as users, SUM(CASE WHEN d.app_user_id IS NULL THEN 1 ELSE 0 END) as anonymous')
            ->first();

        return [
            'devices' => (int) ($row->devices ?? 0),
            'users' => (int) ($row->users ?? 0),
            'anonymous_devices' => (int) ($row->anonymous ?? 0),
            'elapsed_ms' => (int) round((microtime(true) - $start) * 1000),
        ];
    }

    /**
     * ユーザーがセグメントに含まれるか（お知らせの対象判定に使う）。
     * ユーザーの有効な端末のどれか（端末がなければユーザーだけ）で条件を満たせば対象。
     *
     * @param  array<array-key, mixed>|null  $conditions
     */
    public function userMatches(App $app, AppUser $user, ?array $conditions): bool
    {
        $query = DB::table('app_users as u')
            ->leftJoin('devices as d', function ($join) {
                $join->on('d.app_user_id', '=', 'u.id')->whereNull('d.invalidated_at');
            })
            ->where('u.app_id', $app->id)
            ->where('u.id', $user->id);

        return $this->compiler->apply($query, $app, $conditions)->exists();
    }
}
