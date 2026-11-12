<?php

namespace App\Http\Controllers\Org\Apps;

use App\Enums\CampaignStatus;
use App\Enums\CampaignTarget;
use App\Enums\NoticeStatus;
use App\Enums\NoticeTarget;
use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\App;
use App\Models\Notice;
use App\Models\Organization;
use App\Models\PushCampaign;
use App\Models\Segment;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class NoticeController extends Controller
{
    public function index(Organization $organization, App $app): View
    {
        $notices = Notice::query()->withCount('reads')->orderByDesc('is_pinned')->latest('id')->paginate(30);

        return view('org.apps.notices.index', compact('organization', 'app', 'notices'));
    }

    public function create(Organization $organization, App $app): View
    {
        Gate::authorize('edit-content');

        return view('org.apps.notices.form', $this->formData($organization, $app, new Notice(['target_type' => NoticeTarget::All, 'status' => NoticeStatus::Draft])));
    }

    public function store(Request $request, Organization $organization, App $app, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('edit-content');
        $notice = Notice::query()->create($this->validated($request, $app) + ['app_id' => $app->id]);
        $audit->record('notice.created', $notice, ['title' => $notice->title, 'status' => $notice->status->value], 'お知らせを作成: '.$notice->title);

        return redirect()->route('org.apps.notices.edit', [$organization, $app, $notice])->with('status', 'お知らせを保存しました。');
    }

    public function edit(Organization $organization, App $app, Notice $notice): View
    {
        return view('org.apps.notices.form', $this->formData($organization, $app, $notice));
    }

    public function update(Request $request, Organization $organization, App $app, Notice $notice, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('edit-content');
        $notice->update($this->validated($request, $app));
        $audit->record('notice.updated', $notice, AuditLogger::diff($notice), 'お知らせを更新: '.$notice->title);

        return back()->with('status', 'お知らせを保存しました。');
    }

    public function destroy(Organization $organization, App $app, Notice $notice, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('edit-content');
        $notice->delete();
        $audit->record('notice.deleted', $notice, summary: 'お知らせを削除: '.$notice->title);

        return redirect()->route('org.apps.notices.index', [$organization, $app])->with('status', 'お知らせを削除しました。');
    }

    /**
     * 「このお知らせをプッシュ通知する」: お知らせの内容からプッシュの下書きを作る。
     * data には type=notice&id=... を自動で入れ、対象はお知らせと同じにする。
     */
    public function push(Request $request, Organization $organization, App $app, Notice $notice, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('edit-content');
        /** @var AdminUser $admin */
        $admin = $request->user('admin');

        $campaign = PushCampaign::query()->create([
            'app_id' => $app->id,
            'title' => $notice->title,
            'body' => Str::limit(trim((string) preg_replace('/[#>*_`\[\]()!-]+/', ' ', strip_tags($notice->body))), 150),
            'image_url' => $notice->thumbnail_url !== null && str_starts_with($notice->thumbnail_url, 'https://') ? $notice->thumbnail_url : null,
            'data' => ['type' => 'notice', 'id' => (string) $notice->id],
            'target_type' => $notice->target_type === NoticeTarget::Segment ? CampaignTarget::Segment : CampaignTarget::All,
            'segment_id' => $notice->target_type === NoticeTarget::Segment ? $notice->segment_id : null,
            'status' => CampaignStatus::Draft,
            'source_type' => 'notice',
            'source_id' => $notice->id,
            'created_by' => $admin->email,
        ]);
        $audit->record('push.campaign_created', $campaign, ['source' => 'notice#'.$notice->id], 'お知らせからプッシュを作成: '.$notice->title);

        return redirect()->route('org.apps.campaigns.edit', [$organization, $app, $campaign])
            ->with('status', 'お知らせからプッシュの下書きを作りました。本文を確認して送信してください。');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, App $app): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:191'],
            'body' => ['required', 'string', 'max:100000'],
            'thumbnail_url' => ['nullable', 'url', 'max:2048'],
            'publish_start_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'publish_end_at' => ['nullable', 'date_format:Y-m-d\TH:i', 'after:publish_start_at'],
            'target_type' => ['required', Rule::enum(NoticeTarget::class)],
            'segment_id' => ['nullable', 'required_if:target_type,segment', 'integer', Rule::exists('segments', 'id')->where('app_id', $app->id)],
            'status' => ['required', Rule::enum(NoticeStatus::class)],
        ]);
        $tz = $app->displayTimezone();
        $parse = fn (?string $v) => $v ? CarbonImmutable::createFromFormat('Y-m-d\TH:i', $v, $tz)?->utc() : null;

        return [
            'title' => $data['title'],
            'body' => $data['body'],
            'thumbnail_url' => $data['thumbnail_url'] ?? null,
            'publish_start_at' => $parse($data['publish_start_at'] ?? null),
            'publish_end_at' => $parse($data['publish_end_at'] ?? null),
            'target_type' => $data['target_type'],
            'segment_id' => $data['target_type'] === NoticeTarget::Segment->value ? $data['segment_id'] : null,
            'is_pinned' => $request->boolean('is_pinned'),
            'status' => $data['status'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Organization $organization, App $app, Notice $notice): array
    {
        return ['organization' => $organization, 'app' => $app, 'notice' => $notice, 'segments' => Segment::query()->orderBy('name')->get()];
    }
}
