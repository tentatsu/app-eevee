<?php

namespace App\Models;

use Database\Factories\SystemAdminFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * システム管理者（Oneframe社員）。/system 配下の管理画面にログインする。
 */
class SystemAdmin extends Authenticatable
{
    /** @use HasFactory<SystemAdminFactory> */
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
}
