<?php

namespace App\Http\Controllers\SiteAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Family;
use App\Models\FamilyInvitation;
use App\Models\FamilyMembership;
use App\Models\FamilyTree;
use App\Models\Person;
use App\Services\Dashboards\SiteHealthService;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(SiteHealthService $health): View
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403);

        return view('site-admin.dashboard', [
            'families' => Family::query()->withCount(['familyTrees', 'users'])->orderBy('name_ar')->paginate(10, ['*'], 'families_page')->withQueryString(),
            'auditLogs' => AuditLog::query()->when(! \App\Support\MaintenanceTools::enabled('audit_viewer'), fn ($query) => $query->whereRaw('1 = 0'))->with(['family', 'user'])->latest()->paginate(15, ['*'], 'audit_page')->withQueryString(),
            'checks' => \App\Support\MaintenanceTools::enabled('diagnostics') ? $health->checks() : [],
            'maintenanceTools' => collect(\App\Support\MaintenanceTools::LABELS)->map(fn ($label, $name) => ['label' => $label, 'enabled' => \App\Support\MaintenanceTools::enabled($name)]),
            'attention' => $health->familiesNeedingAttention(),
            'statistics' => [
                ...$health->statistics(),
                'trees' => FamilyTree::query()->count(),
                'people' => Person::query()->count(),
                'memberships' => FamilyMembership::query()->count(),
                'audit' => AuditLog::query()->count(),
                'pending_invitations' => FamilyInvitation::query()->whereNull('accepted_at')->whereNull('cancelled_at')->where('expires_at', '>', now())->count(),
            ],
        ]);
    }

    public function updateTools(\Illuminate\Http\Request $request): \Illuminate\Http\RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $data = $request->validate([
            'seed_audit' => ['required', 'boolean'],
            'reconciliation' => ['required', 'boolean'],
            'diagnostics' => ['required', 'boolean'],
            'audit_viewer' => ['required', 'boolean'],
            'genealogy_administration' => ['required', 'boolean'],
            'permissions_administration' => ['required', 'boolean'],
        ]);
        \Illuminate\Support\Facades\DB::transaction(function () use ($data, $request): void {
            $before = \Illuminate\Support\Facades\DB::table('maintenance_tool_settings')->pluck('enabled', 'name')->all();
            foreach ($data as $name => $enabled) {
                \Illuminate\Support\Facades\DB::table('maintenance_tool_settings')->updateOrInsert(['name' => $name], ['enabled' => (bool) $enabled, 'created_at' => now(), 'updated_at' => now()]);
            }
            app(\App\Services\AuditLogger::class)->record($request->user(), null, $request->user(), 'tool_settings_updated', $before, $data);
        });

        return back()->with('status', 'تم حفظ إعدادات الأدوات.');
    }

}
