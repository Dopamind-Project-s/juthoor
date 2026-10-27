<?php

namespace App\Http\Controllers;

use App\Models\Assertion;
use App\Models\AuditLog;
use App\Models\Person;
use App\Services\AuditReversionService;
use App\Services\Genealogy\PersonPrivacy;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function show(int $auditLog): View
    {
        $log = AuditLog::query()->with(['family', 'user'])->findOrFail($auditLog);
        abort_unless($log->canBeViewedBy(auth()->user()), 403);
        // Computed before redaction: the check compares the stored snapshot with the current record.
        $recovery = $log->family && auth()->user()->can('recover', $log->family) && $log->family->is_active
            ? app(AuditReversionService::class)->eligibility($log) : null;
        if ($log->auditable_type === Assertion::class) {
            $privacy = app(PersonPrivacy::class);
            $log->setAttribute('before', $log->before ? $privacy->redactFactSnapshot($log->before, auth()->user()) : null);
            $log->setAttribute('after', $log->after ? $privacy->redactFactSnapshot($log->after, auth()->user()) : null);
        }
        if ($log->family && $log->auditable_type === Person::class) {
            $privacy = app(PersonPrivacy::class);
            $current = Person::query()->find($log->auditable_id);
            // In-memory only: the stored audit snapshot is never altered.
            $log->setAttribute('before', $log->before ? $privacy->redactSnapshot($log->before, $log->family, auth()->user(), $current) : null);
            $log->setAttribute('after', $log->after ? $privacy->redactSnapshot($log->after, $log->family, auth()->user(), $current) : null);
        }

        return view('management.audit.show', ['log' => $log, 'family' => $log->family, 'recovery' => $recovery]);
    }
}
