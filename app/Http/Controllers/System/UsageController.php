<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Models\App;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * アプリごとの動画の利用量（共用アカウントの費用を顧客に請求するための目安）。
 */
class UsageController extends Controller
{
    public function index(Request $request): View
    {
        $month = $request->string('month')->value();
        $start = preg_match('/^\d{4}-\d{2}$/', $month) === 1
            ? CarbonImmutable::createFromFormat('Y-m-d', $month.'-01', 'Asia/Tokyo')?->startOfMonth()
            : null;
        $start ??= CarbonImmutable::now('Asia/Tokyo')->startOfMonth();
        $end = $start->addMonth();

        $apps = App::withoutGlobalScopes()->with('organization', 'videoProviderAccount')->orderBy('organization_id')->orderBy('name')->get();

        $stored = DB::table('videos')->where('status', 'ready')->groupBy('app_id')
            ->selectRaw('app_id, COUNT(*) as videos, COALESCE(SUM(duration_seconds), 0) as seconds')->get()->keyBy('app_id');
        $watched = DB::table('playback_sessions')
            ->where('started_at', '>=', $start->utc())->where('started_at', '<', $end->utc())
            ->groupBy('app_id')->selectRaw('app_id, COUNT(*) as plays, COALESCE(SUM(watched_seconds), 0) as seconds')->get()->keyBy('app_id');
        $pushes = DB::table('push_deliveries as d')->join('push_campaigns as c', 'c.id', '=', 'd.push_campaign_id')
            ->where('d.status', 'sent')->where('d.sent_at', '>=', $start->utc())->where('d.sent_at', '<', $end->utc())
            ->groupBy('c.app_id')->selectRaw('c.app_id, COUNT(*) as sent')->pluck('sent', 'c.app_id');

        $rows = $apps->map(fn (App $app) => [
            'app' => $app,
            'videos' => (int) ($stored[$app->id]->videos ?? 0),
            'stored_minutes' => (int) ceil(((int) ($stored[$app->id]->seconds ?? 0)) / 60),
            'plays' => (int) ($watched[$app->id]->plays ?? 0),
            'watched_minutes' => (int) ceil(((int) ($watched[$app->id]->seconds ?? 0)) / 60),
            'pushes' => (int) ($pushes[$app->id] ?? 0),
        ]);

        return view('system.usage', ['rows' => $rows, 'start' => $start, 'months' => collect(range(0, 11))->map(fn ($i) => CarbonImmutable::now('Asia/Tokyo')->startOfMonth()->subMonths($i))]);
    }
}
