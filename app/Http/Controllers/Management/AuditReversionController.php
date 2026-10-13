<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\AuditReversionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuditReversionController extends Controller
{
    public function __construct(private AuditReversionService $reversions) {}

    private function log(int $id): AuditLog
    {
        $log = AuditLog::query()->with('family')->findOrFail($id);
        abort_unless($log->family?->is_active, 404);
        Gate::authorize('review', $log->family);

        return $log;
    }

    public function preview(int $auditLog): View
    {
        $log = $this->log($auditLog);
        $blocked = null;
        $context = null;
        try {
            $context = $this->reversions->preview($log);
        } catch (ValidationException $e) {
            $blocked = collect($e->errors())->flatten()->first();
        }

        return view('management.audit.revert', ['log' => $log, 'family' => $log->family, 'tree' => $context['tree'] ?? null, 'context' => $context, 'blocked' => $blocked, 'referenceLabels' => $context['referenceLabels'] ?? []]);
    }

    public function store(Request $request, int $auditLog): RedirectResponse
    {
        $log = $this->log($auditLog);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000'], 'confirm' => ['required', 'accepted']]);
        $new = $this->reversions->revert($log, $request->user(), $data['reason']);

        return redirect()->route('management.audit.show', $new->id)->with('status', 'سُجل الاسترجاع كتغيير جديد مع إبقاء العملية الأصلية.');
    }
}
