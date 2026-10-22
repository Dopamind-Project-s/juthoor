<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Person;
use App\Services\Genealogy\PersonPrivacy;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function show(int $auditLog): View
    {
        $log = AuditLog::query()->with(['family', 'user'])->findOrFail($auditLog);
        abort_unless($log->canBeViewedBy(auth()->user()), 403);
        if ($log->family && $log->auditable_type === Person::class) {
            $privacy = app(PersonPrivacy::class);
            $current = Person::query()->find($log->auditable_id);
            // In-memory only: the stored audit snapshot is never altered.
            $log->setAttribute('before', $log->before ? $privacy->redactSnapshot($log->before, $log->family, auth()->user(), $current) : null);
            $log->setAttribute('after', $log->after ? $privacy->redactSnapshot($log->after, $log->family, auth()->user(), $current) : null);
        }

        return view('management.audit.show', ['log' => $log, 'family' => $log->family]);
    }
}
