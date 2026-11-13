<?php

namespace App\Models;

use App\Enums\OrganizationStatus;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory, SoftDeletes;

    /** slug に使えない予約語 */
    public const RESERVED_SLUGS = [
        'system', 'api', 'login', 'logout', 'admin', 'webhooks', 'health', 'up',
        'assets', 'storage', 'css', 'js', 'build', 'vendor', 'horizon', 'docs',
        'sanctum', 'livewire', 'broadcasting', 'favicon.ico', 'robots.txt',
        'invitations', 'password', 'register', 'static', 'public', 'www', 'mail', 'billing',
    ];

    /** slug の書式（英小文字・数字・ハイフン、3〜32文字、先頭末尾は英数字） */
    public const SLUG_PATTERN = '[a-z0-9](?:[a-z0-9-]{1,30})[a-z0-9]';

    protected $fillable = ['slug', 'name', 'status', 'timezone', 'stripe_account_id', 'stripe_charges_enabled', 'stripe_details_submitted', 'application_fee_percent'];

    protected function casts(): array
    {
        return [
            'status' => OrganizationStatus::class,
            'stripe_charges_enabled' => 'boolean',
            'stripe_details_submitted' => 'boolean',
            'application_fee_percent' => 'float',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public static function isValidSlug(string $slug): bool
    {
        return preg_match('/^'.self::SLUG_PATTERN.'$/', $slug) === 1
            && ! in_array($slug, self::RESERVED_SLUGS, true);
    }

    public function isActive(): bool
    {
        return $this->status === OrganizationStatus::Active;
    }

    /**
     * @return BelongsToMany<AdminUser, $this, OrganizationMember, 'membership'>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(AdminUser::class, 'organization_members')
            ->using(OrganizationMember::class)
            ->as('membership')
            ->withPivot('role')
            ->withTimestamps();
    }

    /** @return HasMany<App, $this> */
    public function apps(): HasMany
    {
        return $this->hasMany(App::class);
    }

    /** @return HasMany<IpAllowEntry, $this> */
    public function ipAllowEntries(): HasMany
    {
        return $this->hasMany(IpAllowEntry::class);
    }

    /** @return HasMany<OrganizationInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(OrganizationInvitation::class);
    }
}
