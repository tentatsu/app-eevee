<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\Audit\AuditLogger;
use App\Services\IpAccess\IpAllowlistService;
use Illuminate\Console\Command;

/**
 * 緊急時に管理画面の IP 制限を解除する。
 */
class ClearIpAllowlist extends Command
{
    protected $signature = 'eevee:ip-allowlist:clear
        {org_slug? : 対象の組織の slug}
        {--system : システム管理画面の許可リスト（DB分）を解除する}';

    protected $description = '管理画面のIP許可リストを空にする（締め出された場合の救済用）';

    public function handle(IpAllowlistService $service, AuditLogger $audit): int
    {
        if ($this->option('system')) {
            $count = $service->clear(null);
            $audit->record('system.ip_allowlist.cleared', null, [], 'artisan でシステム管理画面のIP許可リストを解除');
            $this->info("システム管理画面の許可リスト（DB分）を {$count} 件削除しました。");
            if ($service->envSystemRanges() !== []) {
                $this->warn('.env の SYSTEM_IP_ALLOWLIST にも値が設定されています。必要なら .env を編集してください。');
            }

            return self::SUCCESS;
        }

        $slug = (string) $this->argument('org_slug');
        if ($slug === '') {
            $this->error('組織の slug を指定するか、--system を指定してください。');

            return self::INVALID;
        }

        $organization = Organization::query()->where('slug', $slug)->first();
        if ($organization === null) {
            $this->error("組織 {$slug} が見つかりません。");

            return self::FAILURE;
        }

        $before = $service->dbRanges($organization);
        $count = $service->clear($organization);
        $audit->record('ip_allowlist.cleared', $organization, ['old' => $before, 'new' => []], 'artisan でIP制限を解除', $organization);
        $this->info("組織 {$slug} のIP許可リストを {$count} 件削除しました（制限なしになりました）。");

        return self::SUCCESS;
    }
}
