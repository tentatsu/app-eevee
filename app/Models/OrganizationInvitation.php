<?php

namespace App\Models;

use App\Enums\OrgRole;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class OrganizationInvitation extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'email', 'role', 'token_hash', 'invited_by', 'expires_at', 'accepted_at'];

    protected function casts(): array
    {
        return [
            'role' => OrgRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /**
     * 招待を作成し、平文トークンを返す（DBにはハッシュのみ保存）。
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(Organization $organization, string $email, OrgRole $role, ?string $invitedBy): array
    {
        $token = Str::random(48);
        $invitation = self::withoutGlobalScopes()->create([
            'organization_id' => $organization->id,
            'email' => mb_strtolower($email),
            'role' => $role,
            'token_hash' => hash('sha256', $token),
            'invited_by' => $invitedBy,
            'expires_at' => now()->addDays((int) config('eevee.invitation_ttl_days', 7)),
        ]);

        return [$invitation, $token];
    }

    public static function findByToken(Organization $organization, string $token): ?self
    {
        return self::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('token_hash', hash('sha256', $token))
            ->first();
    }

    public function isUsable(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }
}
