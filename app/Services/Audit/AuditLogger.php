<?php

namespace App\Services\Audit;

use App\Models\AdminUser;
use App\Models\App;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\SystemAdmin;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * 管理画面での操作を「誰が・いつ・どの組織で・何を・どのIPから」記録する。
 */
class AuditLogger
{
    /** 値をマスクするキー（部分一致） */
    private const SECRET_KEY_PATTERN = '/(secret|token|password|private_key|credential|api_key|pem)/i';

    public function __construct(private readonly TenantContext $tenant, private readonly Request $request) {}

    /**
     * @param  array<string, mixed>  $changes
     */
    public function record(
        string $action,
        ?Model $subject = null,
        array $changes = [],
        ?string $summary = null,
        ?Organization $organization = null,
        ?App $app = null,
    ): AuditLog {
        [$actorType, $actor] = $this->resolveActor();

        $organization ??= $this->tenant->organization();
        $app ??= $this->tenant->app() ?? ($subject instanceof App ? $subject : null);
        if ($app === null && $subject !== null && isset($subject->app_id)) {
            $app = App::withoutGlobalScopes()->find($subject->app_id);
        }

        $log = new AuditLog;
        $log->forceFill([
            'organization_id' => $organization?->id,
            'app_id' => $app?->id,
            'actor_type' => $actorType,
            'actor_id' => $actor?->getKey(),
            'actor_name' => $actor ? ($actor->name.' <'.$actor->email.'>') : null,
            'action' => $action,
            'subject_type' => $subject ? Str::snake(class_basename($subject)) : null,
            'subject_id' => $subject?->getKey(),
            'summary' => $summary !== null ? Str::limit($summary, 250) : null,
            'changes' => $changes === [] ? null : self::mask($changes),
            'ip_address' => app()->runningInConsole() && ! app()->runningUnitTests() ? null : $this->request->ip(),
            'user_agent' => Str::limit((string) $this->request->userAgent(), 500, ''),
        ]);
        // 監査ログは組織スコープの外（システム操作）からも書けるようにする
        AuditLog::withoutEvents(fn () => $log->save());

        return $log;
    }

    /**
     * Eloquent の変更差分を記録用の形式にする。
     *
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public static function diff(Model $model): array
    {
        $out = [];
        foreach ($model->getChanges() as $key => $new) {
            if (in_array($key, ['updated_at', 'created_at'], true)) {
                continue;
            }
            $out[$key] = ['old' => $model->getOriginal($key), 'new' => $new];
        }

        return $out;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function mask(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET_KEY_PATTERN, $key)) {
                $data[$key] = '***';
            } elseif (is_array($value)) {
                $data[$key] = self::mask($value);
            } elseif ($value instanceof \BackedEnum) {
                $data[$key] = $value->value;
            }
        }

        return $data;
    }

    /**
     * @return array{0: string, 1: AdminUser|SystemAdmin|null}
     */
    private function resolveActor(): array
    {
        if ($this->request->is('system', 'system/*')) {
            /** @var SystemAdmin|null $user */
            $user = Auth::guard('system')->user();

            return ['system_admin', $user];
        }
        /** @var AdminUser|null $admin */
        $admin = Auth::guard('admin')->user();
        if ($admin !== null) {
            return ['admin_user', $admin];
        }

        return [app()->runningInConsole() ? 'console' : 'system', null];
    }
}
