<?php

namespace App\Models;

use App\Enums\OrgRole;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $admin_user_id
 * @property OrgRole $role
 */
class OrganizationMember extends Pivot
{
    protected $table = 'organization_members';

    public $incrementing = true;

    protected $fillable = ['organization_id', 'admin_user_id', 'role'];

    protected function casts(): array
    {
        return ['role' => OrgRole::class];
    }

    /** @return BelongsTo<AdminUser, $this> */
    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
