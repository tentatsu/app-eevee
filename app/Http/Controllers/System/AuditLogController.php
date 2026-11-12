<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $query = AuditLog::query()->with('organization')->latest('id');
        if ($org = $request->integer('organization_id')) {
            $query->where('organization_id', $org);
        }
        $logs = $query->paginate(50)->withQueryString();

        return view('system.audit-logs', compact('logs'));
    }
}
