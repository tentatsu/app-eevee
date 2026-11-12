<?php

namespace App\Console\Commands;

use App\Models\SystemAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateSystemAdmin extends Command
{
    protected $signature = 'eevee:system-admin:create {email} {--name=System Admin} {--password=}';

    protected $description = 'システム管理者を作成する（パスワード省略時は対話入力）';

    public function handle(): int
    {
        $email = mb_strtolower((string) $this->argument('email'));
        $password = (string) ($this->option('password') ?: $this->secret('パスワード'));

        $validator = Validator::make(compact('email', 'password'), [
            'email' => ['required', 'email', 'unique:system_admins,email'],
            'password' => ['required', 'min:8'],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        SystemAdmin::query()->create(['name' => (string) $this->option('name'), 'email' => $email, 'password' => $password]);
        $this->info("システム管理者 {$email} を作成しました。");

        return self::SUCCESS;
    }
}
