<?php

namespace App\Http\Controllers\SiteAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CommunityEntity;
use App\Models\Family;
use App\Models\FamilyInvitation;
use App\Models\FamilyMembership;
use App\Models\FamilyTree;
use App\Models\Person;
use App\Services\AuditLogger;
use App\Services\Dashboards\AdministrationWorkspace;
use App\Services\Dashboards\SiteHealthService;
use App\Support\MaintenanceTools;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(SiteHealthService $health): View
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403);

        $workspace = app(AdministrationWorkspace::class)->build(auth()->user(), null);
        $toolStates = $workspace['tools'];
        $statistics = $health->statistics();

        return view('site-admin.dashboard', [
            'adminWorkspace' => $workspace,
            'families' => Family::query()->withCount(['familyTrees', 'users'])->orderBy('name_ar')->paginate(10, ['*'], 'families_page')->withQueryString(),
            'auditLogs' => AuditLog::query()->when(! $toolStates['audit_viewer'], fn ($query) => $query->whereRaw('1 = 0'))->with(['family', 'user'])->latest()->paginate(15, ['*'], 'audit_page')->withQueryString(),
            'checks' => $toolStates['diagnostics'] ? $health->checks() : $health->overviewChecks($statistics),
            'maintenanceTools' => collect(MaintenanceTools::LABELS)->map(fn ($label, $name) => ['label' => $label, 'enabled' => $toolStates[$name]]),
            'attention' => $health->familiesNeedingAttention(),
            'statistics' => [
                ...$statistics,
                'published_entities' => CommunityEntity::query()->where('publication_status', 'published')->count(),
                'trees' => FamilyTree::query()->count(),
                'people' => Person::query()->count(),
                'memberships' => FamilyMembership::query()->count(),
                'audit' => AuditLog::query()->count(),
                'pending_invitations' => FamilyInvitation::query()->whereNull('accepted_at')->whereNull('cancelled_at')->where('expires_at', '>', now())->count(),
            ],
        ]);
    }

    public function updateTools(Request $request): RedirectResponse
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
        DB::transaction(function () use ($data, $request): void {
            $before = DB::table('maintenance_tool_settings')->pluck('enabled', 'name')->all();
            foreach ($data as $name => $enabled) {
                DB::table('maintenance_tool_settings')->updateOrInsert(['name' => $name], ['enabled' => (bool) $enabled, 'created_at' => now(), 'updated_at' => now()]);
            }
            app(AuditLogger::class)->record($request->user(), null, $request->user(), 'tool_settings_updated', $before, $data);
        });

        return back()->with('status', 'تم حفظ إعدادات الأدوات.');
    }
}
