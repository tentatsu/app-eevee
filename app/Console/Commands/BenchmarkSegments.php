<?php

namespace App\Console\Commands;

use App\Models\App;
use App\Services\Segments\SegmentQuery;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * 代表的なセグメント条件で抽出（プレビュー）にかかる時間を計測する。
 */
class BenchmarkSegments extends Command
{
    protected $signature = 'eevee:segments:benchmark {app_key} {--runs=3}';

    protected $description = 'セグメント抽出の性能を計測する（PerformanceSeeder と組み合わせて使う）';

    public function handle(SegmentQuery $query, TenantContext $tenant): int
    {
        $app = App::withoutGlobalScopes()->where('app_key', (string) $this->argument('app_key'))->first();
        if ($app === null) {
            $this->error('アプリが見つかりません。');

            return self::FAILURE;
        }
        $tenant->setApp($app);

        $cases = [
            '全員' => ['op' => 'and', 'conditions' => []],
            '属性 eq（性別=female）' => self::c('attr.gender', 'eq', 'female'),
            '属性 between（誕生月 4〜6）' => self::c('attr.birth_month', 'between', [4, 6]),
            '属性 decimal gte（累計金額≧10000）' => self::c('attr.total_amount', 'gte', 10000),
            '属性 date within_days（30日以内に購入）' => self::c('attr.last_purchase_at', 'within_days', 30),
            '端末（iOS かつ v1.5.0以上 かつ 7日以内にアクティブ）' => ['op' => 'and', 'conditions' => [
                ['field' => 'device.platform', 'operator' => 'eq', 'value' => 'ios'],
                ['field' => 'device.app_version', 'operator' => 'gte', 'value' => '1.5.0'],
                ['field' => 'device.last_active_at', 'operator' => 'within_days', 'value' => 7],
            ]],
            '入れ子（(female AND 購入≧10) OR premium）AND カテゴリON' => ['op' => 'and', 'conditions' => [
                ['op' => 'or', 'conditions' => [
                    ['op' => 'and', 'conditions' => [
                        ['field' => 'attr.gender', 'operator' => 'eq', 'value' => 'female'],
                        ['field' => 'attr.purchase_count', 'operator' => 'gte', 'value' => 10],
                    ]],
                    ['field' => 'attr.premium', 'operator' => 'eq', 'value' => true],
                ]],
                ['field' => 'category.campaign', 'operator' => 'eq', 'value' => true],
            ]],
        ];

        $runs = max(1, (int) $this->option('runs'));
        $rows = [];
        foreach ($cases as $label => $tree) {
            $result = $query->preview($app, $tree);
            $times = [$result['elapsed_ms']];
            for ($i = 1; $i < $runs; $i++) {
                $times[] = $query->preview($app, $tree)['elapsed_ms'];
            }
            // 送信時と同じく、対象の端末 ID を全件取り出す時間も計る
            $start = microtime(true);
            $ids = count($query->pushableDevices($app, $tree)->pluck('d.id')->all());
            $fetchMs = (int) round((microtime(true) - $start) * 1000);

            $rows[] = [$label, number_format($result['devices']), number_format($result['users']), min($times).' / '.max($times), $fetchMs.' ('.number_format($ids).'件)'];
        }

        $this->info('端末数: '.number_format($app->devices()->withoutGlobalScopes()->where('app_id', $app->id)->count()));
        $this->table(['条件', '対象端末', '対象ユーザー', 'プレビュー ms（最小/最大）', 'ID全件取得 ms'], $rows);

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private static function c(string $field, string $operator, mixed $value): array
    {
        return ['op' => 'and', 'conditions' => [['field' => $field, 'operator' => $operator, 'value' => $value]]];
    }
}
