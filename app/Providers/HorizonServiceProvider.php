<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Horizon のダッシュボード（/horizon）はシステム管理者だけが見られる。
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            return auth('system')->check();
        });
    }
}
