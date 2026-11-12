<?php

namespace App\Http\Controllers\Org;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Organization $organization): View
    {
        $apps = $organization->apps()->withCount(['appUsers', 'devices'])->orderBy('name')->get();

        return view('org.dashboard', ['organization' => $organization, 'apps' => $apps]);
    }
}
