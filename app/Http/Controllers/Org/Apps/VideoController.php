<?php

namespace App\Http\Controllers\Org\Apps;

use App\Enums\VideoStatus;
use App\Enums\VideoVisibility;
use App\Http\Controllers\Controller;
use App\Models\App;
use App\Models\BillingPlan;
use App\Models\Organization;
use App\Models\Video;
use App\Services\Audit\AuditLogger;
use App\Services\Video\VideoProviderException;
use App\Services\Video\VideoProviderManager;
use App\Services\Video\VideoSynchronizer;
use App\Services\Video\VideoThumbnails;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class VideoController extends Controller
{
    public function index(Organization $organization, App $app, VideoThumbnails $thumbnails): View
    {
        $videos = Video::query()->orderBy('sort_order')->orderByDesc('id')->paginate(30);
        $stats = $this->stats(collect($videos->items())->pluck('id')->all());
        $thumbs = [];
        foreach ($videos->items() as $v) {
            $thumbs[$v->id] = $thumbnails->url($app, $v);
        }

        return view('org.apps.videos.index', compact('organization', 'app', 'videos', 'stats', 'thumbs'));
    }

    public function create(Organization $organization, App $app): View
    {
        Gate::authorize('edit-content');

        return view('org.apps.videos.form', ['organization' => $organization, 'app' => $app, 'video' => new Video(['visibility' => VideoVisibility::Members, 'sort_order' => 0])]);
    }

    /**
     * 動画の情報を保存し、プロバイダにアップロード先を作って、アップロード画面へ進む。
     */
    public function store(Request $request, Organization $organization, App $app, VideoProviderManager $providers, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('edit-content');
        $data = $this->validated($request, $app);

        try {
            $upload = $providers->for($app)->createDirectUpload($app, $data['title'], (int) config('eevee.video.max_upload_duration_seconds', 21600));
        } catch (VideoProviderException $e) {
            throw ValidationException::withMessages(['provider' => '動画プロバイダに接続できません。アプリ設定の Cloudflare Stream の設定を確認してください。（'.$e->getMessage().'）']);
        }

        $video = Video::query()->create($data + [
            'app_id' => $app->id,
            'provider' => $app->video_provider,
            'provider_video_id' => $upload->providerVideoId,
            'status' => VideoStatus::PendingUpload,
        ]);
        $audit->record('video.created', $video, ['title' => $video->title, 'provider_video_id' => $video->provider_video_id], '動画を作成: '.$video->title);

        return redirect()->route('org.apps.videos.edit', [$organization, $app, $video])
            ->with('upload_url', $upload->uploadUrl)
            ->with('status', '動画ファイルを選んでアップロードしてください。');
    }

    public function edit(Organization $organization, App $app, Video $video, VideoThumbnails $thumbnails): View
    {
        $stats = $this->stats([$video->id])[$video->id] ?? null;
        $recent = $video->playbackSessions()->latest('started_at')->limit(20)->get();
        $thumb = $thumbnails->url($app, $video);

        return view('org.apps.videos.form', compact('organization', 'app', 'video', 'stats', 'recent', 'thumb'));
    }

    public function update(Request $request, Organization $organization, App $app, Video $video, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('edit-content');
        $video->update($this->validated($request, $app));
        $audit->record('video.updated', $video, AuditLogger::diff($video), '動画を更新: '.$video->title);

        return back()->with('status', '保存しました。');
    }

    public function destroy(Organization $organization, App $app, Video $video, VideoProviderManager $providers, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('edit-content');
        if ($video->provider_video_id !== null) {
            try {
                $providers->for($app)->deleteVideo($app, $video->provider_video_id);
            } catch (VideoProviderException $e) {
                throw ValidationException::withMessages(['provider' => '動画プロバイダ側の削除に失敗しました: '.$e->getMessage()]);
            }
        }
        $video->delete();
        $audit->record('video.deleted', $video, ['provider_video_id' => $video->provider_video_id], '動画を削除: '.$video->title);

        return redirect()->route('org.apps.videos.index', [$organization, $app])->with('status', '動画を削除しました。');
    }

    /**
     * アップロード URL を再発行する（期限切れ・アップロード失敗時）。
     */
    public function uploadUrl(Organization $organization, App $app, Video $video, VideoProviderManager $providers): JsonResponse
    {
        Gate::authorize('edit-content');
        abort_unless(in_array($video->status, [VideoStatus::PendingUpload, VideoStatus::Error], true), 409);
        $provider = $providers->for($app);
        $upload = $provider->createDirectUpload($app, $video->title, (int) config('eevee.video.max_upload_duration_seconds', 21600));

        $old = $video->provider_video_id;
        $video->update(['provider_video_id' => $upload->providerVideoId, 'status' => VideoStatus::PendingUpload, 'status_message' => null]);
        if ($old !== null && $old !== $upload->providerVideoId) {
            rescue(fn () => $provider->deleteVideo($app, $old), report: false);
        }

        return response()->json(['upload_url' => $upload->uploadUrl]);
    }

    /**
     * ブラウザからのアップロード完了の通知（変換の完了は Webhook で反映される）。
     */
    public function uploaded(Organization $organization, App $app, Video $video): JsonResponse
    {
        Gate::authorize('edit-content');
        if ($video->status === VideoStatus::PendingUpload) {
            $video->update(['status' => VideoStatus::Processing]);
        }

        return response()->json(['status' => $video->status->value]);
    }

    /**
     * プロバイダから状態を取り直す（Webhook が届かない環境向け）。
     */
    public function refresh(Organization $organization, App $app, Video $video, VideoSynchronizer $sync): RedirectResponse|JsonResponse
    {
        try {
            $sync->refresh($app, $video);
        } catch (VideoProviderException $e) {
            throw ValidationException::withMessages(['provider' => $e->getMessage()]);
        }

        return request()->expectsJson()
            ? response()->json(['status' => $video->status->value, 'duration_seconds' => $video->duration_seconds])
            : back()->with('status', '状態を更新しました: '.$video->status->label());
    }

    /**
     * 動画ごとの再生回数・視聴時間・同時視聴の超過件数。
     *
     * @param  list<int>  $videoIds
     * @return array<int, array{plays: int, watched_seconds: int, limit_events: int, viewers: int}>
     */
    private function stats(array $videoIds): array
    {
        if ($videoIds === []) {
            return [];
        }
        $plays = DB::table('playback_sessions')->whereIn('video_id', $videoIds)
            ->groupBy('video_id')
            ->selectRaw('video_id, COUNT(*) as plays, COALESCE(SUM(watched_seconds), 0) as watched, COUNT(DISTINCT viewer_key) as viewers')
            ->get()->keyBy('video_id');
        $limits = DB::table('playback_limit_events')->whereIn('video_id', $videoIds)->groupBy('video_id')
            ->selectRaw('video_id, COUNT(*) as c')->pluck('c', 'video_id');

        $out = [];
        foreach ($videoIds as $id) {
            $p = $plays[$id] ?? null;
            $out[$id] = [
                'plays' => (int) ($p->plays ?? 0),
                'watched_seconds' => (int) ($p->watched ?? 0),
                'viewers' => (int) ($p->viewers ?? 0),
                'limit_events' => (int) ($limits[$id] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, App $app): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:10000'],
            'thumbnail_url' => ['nullable', 'url', 'max:2048'],
            'visibility' => ['required', Rule::enum(VideoVisibility::class)],
            'publish_start_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'publish_end_at' => ['nullable', 'date_format:Y-m-d\TH:i', 'after:publish_start_at'],
            'sort_order' => ['nullable', 'integer', 'min:-100000', 'max:100000'],
            'category' => ['nullable', 'string', 'max:64'],
            'tags' => ['nullable', 'string', 'max:1000'],
            'required_entitlement' => ['nullable', 'string', 'regex:'.BillingPlan::ENTITLEMENT_PATTERN],
        ]);
        $tz = $app->displayTimezone();
        $parse = fn (?string $v) => $v ? CarbonImmutable::createFromFormat('Y-m-d\TH:i', $v, $tz)?->utc() : null;
        $tags = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) ($data['tags'] ?? ''))))));

        return [
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'thumbnail_url' => $data['thumbnail_url'] ?? null,
            'visibility' => $data['visibility'],
            'publish_start_at' => $parse($data['publish_start_at'] ?? null),
            'publish_end_at' => $parse($data['publish_end_at'] ?? null),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'category' => $data['category'] ?? null,
            'tags' => $tags === [] ? null : $tags,
            'required_entitlement' => $data['required_entitlement'] ?? null,
        ];
    }
}
