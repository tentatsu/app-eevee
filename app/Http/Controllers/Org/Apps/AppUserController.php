<?php

namespace App\Http\Controllers\Org\Apps;

use App\Http\Controllers\Controller;
use App\Models\App;
use App\Models\AppUser;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AppUserController extends Controller
{
    public function index(Request $request, Organization $organization, App $app): View
    {
        $query = AppUser::query()->withCount('devices')->latest('id');
        if ($q = $request->string('q')->trim()->value()) {
            $query->where(fn ($w) => $w->where('email', 'like', "%{$q}%")->orWhere('external_user_id', $q)->orWhere('name', 'like', "%{$q}%")->orWhere('id', ctype_digit($q) ? (int) $q : 0));
        }
        $users = $query->paginate(50)->withQueryString();

        return view('org.apps.users.index', compact('organization', 'app', 'users'));
    }

    public function show(Organization $organization, App $app, AppUser $user): View
    {
        $user->load('devices');
        $definitions = $app->attributeDefinitions()->get()->keyBy('key');
        $categories = $app->notificationCategories()->get();

        return view('org.apps.users.show', compact('organization', 'app', 'user', 'definitions', 'categories'));
    }
}
