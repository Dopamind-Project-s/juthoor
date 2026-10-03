<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function show(int $auditLog): View
    {
        $log = AuditLog::query()->with(['family', 'user'])->findOrFail($auditLog);
        if ($log->family) {
            Gate::authorize('view', $log->family);
        } else {
            abort_unless(auth()->user()->isSuperAdmin(), 403);
        }

        return view('management.audit.show', ['log' => $log, 'family' => $log->family]);
    }
}
