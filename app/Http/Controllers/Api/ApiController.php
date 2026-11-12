<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\App;
use App\Models\AppUser;
use App\Support\Api\ApiException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;

abstract class ApiController extends Controller
{
    protected function currentApp(): App
    {
        $app = app(TenantContext::class)->app();
        if ($app === null) {
            throw new ApiException('app_not_found', 'The app_key is invalid.', 404);
        }

        return $app;
    }

    protected function appUser(Request $request): AppUser
    {
        $user = $request->user();
        if (! $user instanceof AppUser) {
            throw new ApiException('unauthenticated', 'A valid user token is required.', 401);
        }

        return $user;
    }

    protected function optionalAppUser(Request $request): ?AppUser
    {
        $user = $request->user();

        return $user instanceof AppUser ? $user : null;
    }
}
