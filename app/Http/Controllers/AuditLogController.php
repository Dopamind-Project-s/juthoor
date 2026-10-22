<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function show(int $auditLog): View
    {
        $log = AuditLog::query()->with(['family', 'user'])->findOrFail($auditLog);
        abort_unless($log->canBeViewedBy(auth()->user()), 403);

        return view('management.audit.show', ['log' => $log, 'family' => $log->family]);
    }
}
