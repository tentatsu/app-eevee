<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use BelongsToOrganization;

    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id', 'app_id', 'actor_type', 'actor_id', 'actor_name', 'action',
        'subject_type', 'subject_id', 'summary', 'changes', 'ip_address', 'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
        ];
    }
}
