<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 管理画面のIP許可リストの1行。organization_id が NULL の行はシステム管理画面用。
 */
class IpAllowEntry extends Model
{
    protected $fillable = ['organization_id', 'cidr', 'note'];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForSystem(Builder $query): Builder
    {
        return $query->whereNull('organization_id');
    }
}
