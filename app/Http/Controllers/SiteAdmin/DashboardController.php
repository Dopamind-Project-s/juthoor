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
            'auditLogs' => AuditLog::query()->with(['family', 'user'])->latest()->paginate(15, ['*'], 'audit_page')->withQueryString(),
            'checks' => $health->checks(),
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
}
