<?php

namespace Database\Seeders;

use App\Enums\AttributeType;
use App\Models\App;
use App\Models\AttributeDefinition;
use App\Models\NotificationCategory;
use App\Models\Organization;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 性能確認用: 1アプリに 10万端末（うち 8万はログインユーザー）を作る。任意で実行する。
 *
 *   php artisan db:seed --class=PerformanceSeeder
 *   php artisan eevee:segments:benchmark ak_perf_benchmark_app
 *
 * 端末数は EEVEE_PERF_DEVICES（既定 100000）で変更できる。
 */
class PerformanceSeeder extends Seeder
{
    public const APP_KEY = 'ak_perf_benchmark_app';

    public function run(): void
    {
        $total = (int) (getenv('EEVEE_PERF_DEVICES') ?: 100_000);
        $userRatio = 0.8;

        $org = Organization::query()->firstOrCreate(['slug' => 'perf-test'], ['name' => 'Performance Test', 'timezone' => 'Asia/Tokyo']);
        $app = App::withoutGlobalScopes()->where('app_key', self::APP_KEY)->first();
        if ($app !== null) {
            $this->command?->warn('既存の性能確認用アプリを削除して作り直します。');
            $app->delete();
        }
        $app = new App(['organization_id' => $org->id, 'name' => 'Perf App']);
        $app->app_key = self::APP_KEY;
        $app->save();

        foreach ([
            ['gender', AttributeType::String], ['birth_month', AttributeType::Int], ['purchase_count', AttributeType::Int],
            ['total_amount', AttributeType::Decimal], ['last_purchase_at', AttributeType::Date], ['premium', AttributeType::Bool],
        ] as $i => [$key, $type]) {
            AttributeDefinition::withoutGlobalScopes()->create(['app_id' => $app->id, 'key' => $key, 'type' => $type, 'label' => $key, 'sort_order' => $i]);
        }
        NotificationCategory::withoutGlobalScopes()->create(['app_id' => $app->id, 'key' => 'campaign', 'label' => 'キャンペーン', 'default_enabled' => true]);

        $userCount = (int) ($total * $userRatio);
        $chunk = 2000;
        $now = now();
        mt_srand(42);

        DB::disableQueryLog();
        $this->command?->info("ユーザー {$userCount} 人を作成中…");
        for ($offset = 0; $offset < $userCount; $offset += $chunk) {
            $rows = [];
            for ($i = $offset; $i < min($offset + $chunk, $userCount); $i++) {
                $attrs = [
                    'gender' => ['male', 'female', 'other'][mt_rand(0, 2)],
                    'birth_month' => mt_rand(1, 12),
                    'purchase_count' => mt_rand(0, 50),
                    'total_amount' => round(mt_rand(0, 2_000_000) / 100, 2),
                    'premium' => mt_rand(0, 9) === 0,
                ];
                if (mt_rand(0, 3) > 0) {
                    $attrs['last_purchase_at'] = $now->copy()->subMinutes(mt_rand(0, 60 * 24 * 365))->format('Y-m-d H:i:s');
                }
                $rows[] = [
                    'app_id' => $app->id,
                    'email' => "perf{$i}@example.com",
                    'name' => "Perf {$i}",
                    'custom_attributes' => json_encode($attrs),
                    'notification_preferences' => mt_rand(0, 4) === 0 ? json_encode(['campaign' => false]) : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('app_users')->insert($rows);
        }

        $firstUserId = (int) DB::table('app_users')->where('app_id', $app->id)->min('id');
        $this->command?->info("端末 {$total} 台を作成中…");
        for ($offset = 0; $offset < $total; $offset += $chunk) {
            $rows = [];
            for ($i = $offset; $i < min($offset + $chunk, $total); $i++) {
                $version = '1.'.mt_rand(0, 12).'.'.mt_rand(0, 9);
                [$maj, $min, $pat] = array_map('intval', explode('.', $version));
                $rows[] = [
                    'app_id' => $app->id,
                    'app_user_id' => $i < $userCount ? $firstUserId + $i : null,
                    'installation_id' => (string) Str::uuid(),
                    'fcm_token' => 'perf-token-'.$i,
                    'platform' => mt_rand(0, 1) ? 'ios' : 'android',
                    'app_version' => $version,
                    'app_version_num' => $maj * 1_000_000_000_000 + $min * 1_000_000 + $pat,
                    'os_version' => '17.0',
                    'locale' => 'ja-JP',
                    'timezone' => 'Asia/Tokyo',
                    'push_permission' => mt_rand(0, 9) === 0 ? 'denied' : 'authorized',
                    'last_active_at' => $now->copy()->subMinutes(mt_rand(0, 60 * 24 * 180)),
                    'invalidated_at' => mt_rand(0, 49) === 0 ? $now : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('devices')->insert($rows);
        }

        $this->command?->info('完了: app_key = '.self::APP_KEY);
    }
}
