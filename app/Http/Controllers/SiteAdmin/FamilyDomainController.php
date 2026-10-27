<?php

namespace App\Http\Controllers\SiteAdmin;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Models\FamilyDomain;
use App\Services\Portal\FamilyHosts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Platform-only domain lifecycle: verify after infrastructure checks, activate, disable, reopen. */
class FamilyDomainController extends Controller
{
    public function __construct(private readonly FamilyHosts $hosts) {}

    public function index(Request $request): View
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        return view('site-admin.domains.index', [
            'domains' => FamilyDomain::query()->with(['family:id,name_ar,slug,is_active', 'requester:id,name'])->orderByRaw("case status when 'pending_verification' then 0 when 'verified' then 1 when 'active' then 2 else 3 end")->orderByDesc('id')->get(),
            'families' => Family::query()->orderBy('name_ar')->get(['id', 'name_ar', 'slug']),
            'routingEnabled' => $this->hosts->routingEnabled(),
        ]);
    }

    /** Platform administrators may register a host for any family (still pending until verified). */
    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $data = $request->validate(['family_id' => ['required', 'integer', 'exists:families,id'], 'host' => ['required', 'string', 'max:253']]);
        $this->hosts->request($request->user(), Family::query()->findOrFail($data['family_id']), $data['host']);

        return back()->with('status', 'سُجّل النطاق بانتظار التحقق.');
    }

    public function transition(Request $request, int $domain, string $action): RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $domainModel = FamilyDomain::query()->with('family')->findOrFail($domain);
        match ($action) {
            'verify' => $this->hosts->verify($request->user(), $domainModel, $request->validate([
                'notes' => ['required', 'string', 'max:2000'],
                'dns_confirmed' => ['accepted'], 'tls_confirmed' => ['accepted'], 'proxy_confirmed' => ['accepted'],
            ], ['*.accepted' => 'أكّد كل فحص من فحوص البنية قبل التحقق.'])['notes']),
            'activate' => $this->hosts->activate($request->user(), $domainModel),
            'disable' => $this->hosts->disable($request->user(), $domainModel, $request->input('notes')),
            'reopen' => $this->hosts->reopen($request->user(), $domainModel),
        };

        return back()->with('status', 'حُدّثت حالة النطاق وسُجلت في التدقيق.');
    }
}
