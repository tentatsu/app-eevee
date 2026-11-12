<?php

namespace App\Http\Controllers\Org\Apps;

use App\Enums\CampaignStatus;
use App\Enums\CampaignTarget;
use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\App;
use App\Models\NotificationCategory;
use App\Models\Organization;
use App\Models\PushCampaign;
use App\Models\Segment;
use App\Services\Audit\AuditLogger;
use App\Services\Push\CampaignDispatcher;
use App\Services\Push\CampaignTargetResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

class CampaignController extends Controller
{
    public function index(Organization $organization, App $app): View
    {
        $campaigns = PushCampaign::query()->where('is_test', false)->latest('id')->paginate(30);

        return view('org.apps.campaigns.index', compact('organization', 'app', 'campaigns'));
    }

    public function create(Request $request, Organization $organization, App $app): View
    {
        Gate::authorize('edit-content');
        $campaign = new PushCampaign(['target_type' => CampaignTarget::All, 'status' => CampaignStatus::Draft]);

        return view('org.apps.campaigns.form', $this->formData($organization, $app, $campaign));
    }

    public function store(Request $request, Organization $organization, App $app, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('edit-content');
        /** @var AdminUser $admin */
        $admin = $request->user('admin');
        $campaign = new PushCampaign(['app_id' => $app->id, 'status' => CampaignStatus::Draft, 'created_by' => $admin->email]);
        $campaign->fill($this->validated($request, $app))->save();
        $audit->record('push.campaign_created', $campaign, ['title' => $campaign->title, 'target' => $campaign->target_type->value], 'プッシュを作成: '.$campaign->title);

        return redirect()->route('org.apps.campaigns.show', [$organization, $app, $campaign])->with('status', '下書きを保存しました。内容と対象数を確認して送信してください。');
    }

    public function show(Organization $organization, App $app, PushCampaign $campaign, CampaignTargetResolver $targets): View
    {
        $campaign->load(['segment', 'notificationCategory']);
        $estimate = $campaign->status->isEditable() ? $targets->count($app, $campaign) : null;
        $deliveries = $campaign->deliveries()->with('device')->latest('id')->paginate(50);
        $tests = PushCampaign::query()->where('parent_campaign_id', $campaign->id)->latest('id')->limit(5)->get();
        $testDeviceCount = $app->devices()->pushable()->where('is_test_device', true)->count();

        return view('org.apps.campaigns.show', compact('organization', 'app', 'campaign', 'estimate', 'deliveries', 'tests', 'testDeviceCount'));
    }

    public function edit(Organization $organization, App $app, PushCampaign $campaign): View|RedirectResponse
    {
        Gate::authorize('edit-content');
        if (! $campaign->status->isEditable()) {
            return redirect()->route('org.apps.campaigns.show', [$organization, $app, $campaign]);
        }

        return view('org.apps.campaigns.form', $this->formData($organization, $app, $campaign));
    }

    public function update(Request $request, Organization $organization, App $app, PushCampaign $campaign, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('edit-content');
        if ($campaign->status !== CampaignStatus::Draft) {
            throw ValidationException::withMessages(['status' => '下書きのキャンペーンだけ編集できます（予約済みなら先に予約を取り消してください）。']);
        }
        $campaign->update($this->validated($request, $app));
        $audit->record('push.campaign_updated', $campaign, AuditLogger::diff($campaign), 'プッシュを更新: '.$campaign->title);

        return redirect()->route('org.apps.campaigns.show', [$organization, $app, $campaign])->with('status', '保存しました。');
    }

    public function destroy(Organization $organization, App $app, PushCampaign $campaign, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('edit-content');
        if (! in_array($campaign->status, [CampaignStatus::Draft, CampaignStatus::Canceled], true)) {
            throw ValidationException::withMessages(['status' => '下書きのキャンペーンだけ削除できます。']);
        }
        $campaign->delete();
        $audit->record('push.campaign_deleted', $campaign, summary: 'プッシュを削除: '.$campaign->title);

        return redirect()->route('org.apps.campaigns.index', [$organization, $app])->with('status', '削除しました。');
    }

    /**
     * 送信（即時、または予約日時が未来なら予約）。
     */
    public function send(Organization $organization, App $app, PushCampaign $campaign, CampaignDispatcher $dispatcher, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('edit-content');
        $this->ensureCredentials($app);
        try {
            $dispatcher->send($campaign);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['status' => 'このキャンペーンは送信できません（既に送信済み、または送信中です）。']);
        }
        $scheduled = $campaign->status === CampaignStatus::Scheduled;
        $audit->record($scheduled ? 'push.campaign_scheduled' : 'push.campaign_sent', $campaign, ['scheduled_at' => $campaign->scheduled_at?->toIso8601String()], ($scheduled ? 'プッシュを予約: ' : 'プッシュを送信: ').$campaign->title);

        return back()->with('status', $scheduled ? '予約しました（'.eevee_dt($campaign->scheduled_at).'）。' : '送信を開始しました。');
    }

    public function cancel(Organization $organization, App $app, PushCampaign $campaign, CampaignDispatcher $dispatcher, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('edit-content');
        if (! $dispatcher->cancelSchedule($campaign)) {
            throw ValidationException::withMessages(['status' => '予約済みのキャンペーンだけ取り消せます。']);
        }
        $audit->record('push.campaign_unscheduled', $campaign, summary: 'プッシュの予約を取り消し: '.$campaign->title);

        return back()->with('status', '予約を取り消しました（下書きに戻りました）。');
    }

    public function sendTest(Request $request, Organization $organization, App $app, PushCampaign $campaign, CampaignDispatcher $dispatcher, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('edit-content');
        $this->ensureCredentials($app);
        if ($app->devices()->pushable()->where('is_test_device', true)->doesntExist()) {
            throw ValidationException::withMessages(['test' => 'テスト端末が登録されていません。「端末」画面でテスト端末を登録してください。']);
        }
        /** @var AdminUser $admin */
        $admin = $request->user('admin');
        $test = $dispatcher->sendTest($campaign, $admin->email);
        $audit->record('push.campaign_test_sent', $test, ['parent_campaign_id' => $campaign->id], 'テスト配信: '.$campaign->title);

        return back()->with('status', 'テスト端末に送信しました。');
    }

    private function ensureCredentials(App $app): void
    {
        if (! $app->hasFirebaseCredentials() && ! config('eevee.push.fake')) {
            throw ValidationException::withMessages(['firebase' => 'Firebase の認証情報が設定されていません（アプリ設定で登録してください）。']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, App $app): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:191'],
            'body' => ['required', 'string', 'max:4000'],
            'image_url' => ['nullable', 'url:https', 'max:2048'],
            'data' => ['nullable', 'string', 'max:4000'],
            'notification_category_id' => ['nullable', 'integer', Rule::exists('notification_categories', 'id')->where('app_id', $app->id)],
            'target_type' => ['required', Rule::enum(CampaignTarget::class)],
            'segment_id' => ['nullable', 'required_if:target_type,segment', 'integer', Rule::exists('segments', 'id')->where('app_id', $app->id)],
            'target_user_ids' => ['nullable', 'required_if:target_type,users', 'string', 'max:100000'],
            'scheduled_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
        ]);

        $userIds = null;
        if ($data['target_type'] === CampaignTarget::Users->value) {
            $userIds = array_values(array_unique(array_map('intval', preg_split('/[\s,]+/', (string) $data['target_user_ids'], -1, PREG_SPLIT_NO_EMPTY) ?: [])));
            if ($userIds === [] || in_array(0, $userIds, true)) {
                throw ValidationException::withMessages(['target_user_ids' => 'アプリユーザーのID（数字）をカンマか改行で区切って入力してください。']);
            }
        }

        $scheduledAt = null;
        if (! empty($data['scheduled_at'])) {
            $scheduledAt = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $data['scheduled_at'], $app->displayTimezone())?->utc();
        }

        return [
            'title' => $data['title'],
            'body' => $data['body'],
            'image_url' => $data['image_url'] ?? null,
            'data' => self::parseData($data['data'] ?? null),
            'notification_category_id' => $data['notification_category_id'] ?? null,
            'target_type' => $data['target_type'],
            'segment_id' => $data['target_type'] === CampaignTarget::Segment->value ? $data['segment_id'] : null,
            'target_user_ids' => $userIds,
            'scheduled_at' => $scheduledAt,
        ];
    }

    /**
     * "key=value" の行、または "type=notice&id=123" のクエリ文字列を data にする。
     *
     * @return array<string, string>|null
     */
    public static function parseData(?string $input): ?array
    {
        $input = trim((string) $input);
        if ($input === '') {
            return null;
        }
        $pairs = [];
        foreach (preg_split('/[\r\n&]+/', $input, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $line) {
            if (! str_contains($line, '=')) {
                throw ValidationException::withMessages(['data' => '「キー=値」の形式で入力してください: '.$line]);
            }
            [$k, $v] = array_map('trim', explode('=', $line, 2));
            if ($k === '' || preg_match('/^(from|gcm\.|google\.|eevee_)/', $k)) {
                throw ValidationException::withMessages(['data' => '使えないキーです: '.$k]);
            }
            $pairs[$k] = $v;
        }

        return $pairs;
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Organization $organization, App $app, PushCampaign $campaign): array
    {
        return [
            'organization' => $organization,
            'app' => $app,
            'campaign' => $campaign,
            'segments' => Segment::query()->orderBy('name')->get(),
            'categories' => NotificationCategory::query()->orderBy('sort_order')->get(),
        ];
    }
}
