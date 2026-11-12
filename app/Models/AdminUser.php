<?php

namespace App\Models;

use App\Enums\OrgRole;
use Database\Factories\AdminUserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * 組織の管理画面ユーザー。複数の組織に所属でき、組織ごとにロールを持つ。
 */
class AdminUser extends Authenticatable
{
    /** @use HasFactory<AdminUserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsToMany<Organization, $this, OrganizationMember, 'membership'>
     */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_members')
            ->using(OrganizationMember::class)
            ->as('membership')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function roleIn(Organization $organization): ?OrgRole
    {
        $member = OrganizationMember::query()
            ->where('organization_id', $organization->id)
            ->where('admin_user_id', $this->id)
            ->first();

        return $member?->role;
    }

    public function isMemberOf(Organization $organization): bool
    {
        return $this->roleIn($organization) !== null;
    }
}
