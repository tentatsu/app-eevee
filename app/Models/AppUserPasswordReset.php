<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppUserPasswordReset extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['app_user_id', 'token_hash', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    /** @return BelongsTo<AppUser, $this> */
    public function appUser(): BelongsTo
    {
        return $this->belongsTo(AppUser::class)->withoutGlobalScopes();
    }
}
