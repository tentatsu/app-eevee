<?php

namespace App\Http\Controllers\Org;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function index(Request $request, Organization $organization): View
    {
        $query = AuditLog::query()->latest('id');
        if ($action = $request->string('action')->trim()->value()) {
            $query->where('action', 'like', $action.'%');
        }
        $logs = $query->paginate(50)->withQueryString();

        return view('org.audit-logs', compact('organization', 'logs'));
    }
}
