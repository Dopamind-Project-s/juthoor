<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Family;
use App\Services\AuditReversionService;
use App\Services\Genealogy\SelectedFamilyTree;
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
        [$blocked, $blockers, $context] = [null, [], null];
        try {
            $context = $this->reversions->preview($log);
        } catch (ValidationException $exception) {
            $blockers = collect($exception->errors())->flatten()->all();
            $blocked = $blockers[0] ?? null;
        }

        return view('management.audit.revert', ['log' => $log, 'family' => $log->family, 'tree' => $context['tree'] ?? null, 'context' => $context, 'blocked' => $blocked, 'blockers' => $blockers, 'referenceLabels' => $context['referenceLabels'] ?? []]);
    }

    /** Recovery history of one family: every revert and restoration with its original event. */
    public function history(Request $request, string $family): View
    {
        $familyModel = Family::query()->active()->where('slug', $family)->firstOrFail();
        Gate::authorize('review', $familyModel);
        $recoveries = AuditLog::query()->where('family_id', $familyModel->id)->whereNotNull('reverted_from_id')->with('user')->latest('id')->paginate(30);
        $originals = AuditLog::query()->whereKey($recoveries->pluck('reverted_from_id'))->with('user')->get()->keyBy('id');

        return view('management.audit.recoveries', ['family' => $familyModel, 'tree' => app(SelectedFamilyTree::class)->resolve($familyModel), 'recoveries' => $recoveries, 'originals' => $originals]);
    }

    public function store(Request $request, int $auditLog): RedirectResponse
    {
        $log = $this->log($auditLog);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000'], 'confirm' => ['required', 'accepted'], 'fingerprint' => ['nullable', 'string', 'size:64'], 'confirm_scope' => ['nullable', 'boolean']]);
        $new = $this->reversions->revert($log, $request->user(), $data['reason'], $data['fingerprint'] ?? null, (bool) ($data['confirm_scope'] ?? false));

        return redirect()->route('management.audit.show', $new->id)->with('status', 'سُجل الاسترجاع كتغيير جديد مع إبقاء العملية الأصلية.');
    }
}
