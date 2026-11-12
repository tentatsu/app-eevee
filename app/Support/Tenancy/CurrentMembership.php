<?php

namespace App\Support\Tenancy;

use App\Enums\OrgRole;
use App\Models\AdminUser;

/**
 * 現在のリクエストで操作している管理者と、その組織でのロール。
 */
class CurrentMembership
{
    private ?AdminUser $user = null;

    private ?OrgRole $role = null;

    public function set(AdminUser $user, OrgRole $role): void
    {
        $this->user = $user;
        $this->role = $role;
    }

    public function user(): ?AdminUser
    {
        return $this->user;
    }

    public function role(): ?OrgRole
    {
        return $this->role;
    }
}
