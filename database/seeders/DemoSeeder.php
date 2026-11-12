<?php

namespace Database\Seeders;

use App\Enums\AttributeType;
use App\Enums\AuthMode;
use App\Enums\CampaignStatus;
use App\Enums\CampaignTarget;
use App\Enums\NoticeStatus;
use App\Enums\NoticeTarget;
use App\Enums\OrgRole;
use App\Enums\Platform;
use App\Enums\PushPermission;
use App\Enums\VideoStatus;
use App\Enums\VideoVisibility;
use App\Models\AdminUser;
use App\Models\App;
use App\Models\AppUser;
use App\Models\AttributeDefinition;
use App\Models\Device;
use App\Models\Notice;
use App\Models\NotificationCategory;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\PushCampaign;
use App\Models\Segment;
use App\Models\SystemAdmin;
use App\Models\Video;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * デモデータ（外部サービスに依存しない）。パスワードはすべて "password"。
 *
 * - システム管理者: system@example.com          → /system/login
 * - oneframe: owner/admin/editor/viewer@oneframe.example.com → /oneframe/login
 * - demo-client: owner@demo-client.example.com  → /demo-client/login
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        SystemAdmin::query()->updateOrCreate(['email' => 'system@example.com'], ['name' => 'Oneframe System Admin', 'password' => 'password']);

        $oneframe = Organization::query()->updateOrCreate(['slug' => 'oneframe'], ['name' => 'Oneframe', 'timezone' => 'Asia/Tokyo']);
        $demo = Organization::query()->updateOrCreate(['slug' => 'demo-client'], ['name' => 'Demo Client', 'timezone' => 'Asia/Tokyo']);

        foreach (OrgRole::cases() as $role) {
            $this->member($oneframe, $role->value.'@oneframe.example.com', 'Oneframe '.ucfirst($role->value), $role);
        }
        $this->member($demo, 'owner@demo-client.example.com', 'Demo Owner', OrgRole::Owner);

        $apps = [
            $this->app($oneframe, 'Oneframe Demo App', 'ak_demo_oneframe_app', AuthMode::Builtin),
            $this->app($demo, 'Demo Client App', 'ak_demo_client_app', AuthMode::External),
        ];

        $this->command?->table(['組織', 'アプリ', 'app_key', 'app_secret（今回だけ表示）'], array_map(
            fn (array $a) => [$a[0]->organization()->value('slug'), $a[0]->name, $a[0]->app_key, $a[1] ?? '（既存のため変更なし）'],
            $apps,
        ));
    }

    private function member(Organization $org, string $email, string $name, OrgRole $role): AdminUser
    {
        $user = AdminUser::query()->updateOrCreate(['email' => $email], ['name' => $name, 'password' => 'password']);
        OrganizationMember::query()->updateOrCreate(['organization_id' => $org->id, 'admin_user_id' => $user->id], ['role' => $role]);

        return $user;
    }

    /**
     * @return array{0: App, 1: string|null}
     */
    private function app(Organization $org, string $name, string $appKey, AuthMode $mode): array
    {
        $existing = App::withoutGlobalScopes()->where('app_key', $appKey)->first();
        if ($existing !== null) {
            return [$existing, null];
        }

        $app = new App(['organization_id' => $org->id, 'name' => $name, 'auth_mode' => $mode]);
        $app->app_key = $appKey;
        $app->save();
        $secret = $app->regenerateSecret();

        $this->appData($app);

        return [$app, $secret];
    }

    private function appData(App $app): void
    {
        mt_srand(crc32($app->app_key));
        $defs = [
            ['birth_month', AttributeType::Int, '誕生月', true],
            ['gender', AttributeType::String, '性別', true],
            ['purchase_count', AttributeType::Int, '購入回数', false],
            ['total_amount', AttributeType::Decimal, '累計購入金額', false],
            ['last_purchase_at', AttributeType::Date, '最終購入日時', false],
            ['premium', AttributeType::Bool, 'プレミアム会員', false],
        ];
        foreach ($defs as $i => [$key, $type, $label, $writable]) {
            AttributeDefinition::withoutGlobalScopes()->create(['app_id' => $app->id, 'key' => $key, 'type' => $type, 'label' => $label, 'app_writable' => $writable, 'sort_order' => $i]);
        }
        NotificationCategory::withoutGlobalScopes()->create(['app_id' => $app->id, 'key' => 'notice', 'label' => 'お知らせ', 'default_enabled' => true, 'sort_order' => 1]);
        NotificationCategory::withoutGlobalScopes()->create(['app_id' => $app->id, 'key' => 'campaign', 'label' => 'キャンペーン', 'default_enabled' => true, 'sort_order' => 2]);

        $users = [];
        for ($i = 1; $i <= 30; $i++) {
            $attrs = [
                'birth_month' => mt_rand(1, 12),
                'gender' => ['female', 'male', 'other'][mt_rand(0, 2)],
                'purchase_count' => mt_rand(0, 30),
                'total_amount' => round(mt_rand(0, 300000) / 10, 1),
                'premium' => mt_rand(0, 4) === 0,
            ];
            if (mt_rand(0, 2) > 0) {
                $attrs['last_purchase_at'] = now()->subDays(mt_rand(0, 120))->format('Y-m-d H:i:s');
            }
            $users[] = AppUser::withoutGlobalScopes()->create([
                'app_id' => $app->id,
                'email' => $app->auth_mode === AuthMode::Builtin ? "user{$i}@example.com" : null,
                'password' => $app->auth_mode === AuthMode::Builtin ? 'password' : null,
                'external_user_id' => $app->auth_mode === AuthMode::External ? 'customer-'.str_pad((string) $i, 5, '0', STR_PAD_LEFT) : null,
                'name' => "デモユーザー{$i}",
                'custom_attributes' => $attrs,
                'notification_preferences' => mt_rand(0, 5) === 0 ? ['campaign' => false] : null,
                'last_login_at' => now()->subDays(mt_rand(0, 30)),
            ]);
        }

        foreach ($users as $i => $user) {
            $this->device($app, $user, $i);
        }
        for ($i = 0; $i < 8; $i++) {
            $this->device($app, null, 100 + $i);
        }
        // テスト端末（実機の FCM トークンに差し替えて使う）
        Device::withoutGlobalScopes()->create([
            'app_id' => $app->id, 'installation_id' => (string) Str::uuid(), 'fcm_token' => 'demo-test-device-token',
            'platform' => Platform::Ios, 'app_version' => '1.2.0', 'push_permission' => PushPermission::Authorized,
            'is_test_device' => true, 'test_label' => '開発用 iPhone', 'last_active_at' => now(),
        ]);

        $women = Segment::withoutGlobalScopes()->create(['app_id' => $app->id, 'name' => '女性', 'conditions' => ['op' => 'and', 'conditions' => [
            ['field' => 'attr.gender', 'operator' => 'eq', 'value' => 'female'],
        ]]]);
        Segment::withoutGlobalScopes()->create(['app_id' => $app->id, 'name' => '4月生まれ', 'conditions' => ['op' => 'and', 'conditions' => [
            ['field' => 'attr.birth_month', 'operator' => 'eq', 'value' => 4],
        ]]]);
        Segment::withoutGlobalScopes()->create(['app_id' => $app->id, 'name' => '7日以内にアクティブな iOS（v1.1.0以上）', 'conditions' => ['op' => 'and', 'conditions' => [
            ['field' => 'device.platform', 'operator' => 'eq', 'value' => 'ios'],
            ['field' => 'device.app_version', 'operator' => 'gte', 'value' => '1.1.0'],
            ['field' => 'device.last_active_at', 'operator' => 'within_days', 'value' => 7],
        ]]]);
        Segment::withoutGlobalScopes()->create(['app_id' => $app->id, 'name' => '優良顧客（購入10回以上 または プレミアム）', 'conditions' => ['op' => 'or', 'conditions' => [
            ['field' => 'attr.purchase_count', 'operator' => 'gte', 'value' => 10],
            ['field' => 'attr.premium', 'operator' => 'eq', 'value' => true],
        ]]]);

        $welcome = Notice::withoutGlobalScopes()->create(['app_id' => $app->id, 'title' => 'アプリをリリースしました', 'body' => "# ようこそ\n\n{$app->name} をご利用いただきありがとうございます。\n\n- お知らせ\n- 動画\n\nをお楽しみください。", 'status' => NoticeStatus::Published, 'target_type' => NoticeTarget::All, 'is_pinned' => true, 'publish_start_at' => now()->subDays(10)]);
        Notice::withoutGlobalScopes()->create(['app_id' => $app->id, 'title' => 'メンテナンスのお知らせ', 'body' => "**11月30日 2:00〜4:00** にメンテナンスを行います。\n\nご迷惑をおかけします。", 'status' => NoticeStatus::Published, 'target_type' => NoticeTarget::All, 'publish_start_at' => now()->subDay()]);
        Notice::withoutGlobalScopes()->create(['app_id' => $app->id, 'title' => '女性向けキャンペーン', 'body' => '対象の方だけに表示されるお知らせです。', 'status' => NoticeStatus::Published, 'target_type' => NoticeTarget::Segment, 'segment_id' => $women->id, 'publish_start_at' => now()->subHours(3)]);
        Notice::withoutGlobalScopes()->create(['app_id' => $app->id, 'title' => '（下書き）年末年始の営業について', 'body' => '準備中', 'status' => NoticeStatus::Draft, 'target_type' => NoticeTarget::All]);

        PushCampaign::withoutGlobalScopes()->create([
            'app_id' => $app->id, 'title' => $welcome->title, 'body' => 'アプリをご利用いただきありがとうございます。',
            'data' => ['type' => 'notice', 'id' => (string) $welcome->id], 'target_type' => CampaignTarget::All, 'status' => CampaignStatus::Draft,
            'source_type' => 'notice', 'source_id' => $welcome->id, 'created_by' => 'seeder',
        ]);

        // 動画（Cloudflare に依存しないダミー。EEVEE_VIDEO_FAKE=true なら再生 API も試せる）
        foreach ([
            ['はじめての使い方', VideoVisibility::Public, 95, 'tutorial', ['beginner']],
            ['会員限定レッスン 1', VideoVisibility::Members, 1260, 'lesson', ['member']],
            ['会員限定レッスン 2', VideoVisibility::Members, 1435, 'lesson', ['member']],
        ] as $i => [$title, $visibility, $duration, $category, $tags]) {
            Video::withoutGlobalScopes()->create([
                'app_id' => $app->id, 'provider' => $app->video_provider, 'provider_video_id' => 'demo-'.$app->id.'-'.($i + 1),
                'title' => $title, 'description' => 'デモ用の動画です（実体はありません）。', 'status' => VideoStatus::Ready,
                'visibility' => $visibility, 'duration_seconds' => $duration, 'sort_order' => $i, 'category' => $category, 'tags' => $tags,
            ]);
        }
    }

    private function device(App $app, ?AppUser $user, int $i): void
    {
        $version = '1.'.mt_rand(0, 3).'.'.mt_rand(0, 5);
        Device::withoutGlobalScopes()->create([
            'app_id' => $app->id,
            'app_user_id' => $user?->id,
            'installation_id' => (string) Str::uuid(),
            'fcm_token' => 'demo-token-'.$app->id.'-'.$i,
            'platform' => mt_rand(0, 1) ? Platform::Ios : Platform::Android,
            'app_version' => $version,
            'os_version' => mt_rand(0, 1) ? '18.1' : '15',
            'locale' => 'ja-JP',
            'timezone' => 'Asia/Tokyo',
            'push_permission' => mt_rand(0, 9) === 0 ? PushPermission::Denied : PushPermission::Authorized,
            'last_active_at' => now()->subDays(mt_rand(0, 40)),
        ]);
    }
}
