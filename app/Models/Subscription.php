<?php

namespace App\Models;

use App\Models\Concerns\BelongsToApp;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ユーザーの購読（Stripe のサブスクリプション）。権利（entitlement）の判定に使う。
 */
class Subscription extends Model
{
    use BelongsToApp;

    /** 権利がある状態（past_due は支払いの再試行中。期間内は見られるようにする） */
    public const ENTITLED_STATUSES = ['active', 'trialing', 'past_due'];

    protected $fillable = ['app_id', 'app_user_id', 'billing_plan_id', 'provider', 'provider_subscription_id', 'status', 'entitlement', 'current_period_end', 'cancel_at_period_end', 'canceled_at'];

    protected function casts(): array
    {
        return ['current_period_end' => 'datetime', 'canceled_at' => 'datetime', 'cancel_at_period_end' => 'boolean'];
    }

    /** @return BelongsTo<BillingPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(BillingPlan::class, 'billing_plan_id');
    }

    /** @return BelongsTo<AppUser, $this> */
    public function appUser(): BelongsTo
    {
        return $this->belongsTo(AppUser::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeEntitled(Builder $query): Builder
    {
        return $query->whereIn('status', self::ENTITLED_STATUSES)
            ->where(fn (Builder $q) => $q->whereNull('current_period_end')->orWhere('current_period_end', '>', now()));
    }

    public function isEntitled(): bool
    {
        return in_array($this->status, self::ENTITLED_STATUSES, true) && ($this->current_period_end === null || $this->current_period_end->isFuture());
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'plan_id' => $this->billing_plan_id,
            'entitlement' => $this->entitlement,
            'status' => $this->status,
            'is_active' => $this->isEntitled(),
            'current_period_end' => $this->current_period_end?->toIso8601ZuluString(),
            'cancel_at_period_end' => $this->cancel_at_period_end,
        ];
    }
}
