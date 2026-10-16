<?php

namespace App\Http\Controllers\SiteAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Family;
use App\Models\FamilyInvitation;
use App\Models\FamilyMembership;
use App\Models\FamilyTree;
use App\Models\Person;
use App\Models\User;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403);

        return view('site-admin.dashboard', [
            'families' => Family::query()->with('familyTrees')->withCount(['familyTrees', 'users'])->orderBy('name_ar')->paginate(10, ['*'], 'families_page')->withQueryString(),
            'users' => User::query()->orderBy('name')->paginate(10, ['*'], 'users_page')->withQueryString(),
            'invitations' => FamilyInvitation::query()->with(['family', 'inviter'])->latest()->paginate(10, ['*'], 'invitations_page')->withQueryString(),
            'auditLogs' => AuditLog::query()->with(['family', 'user'])->latest()->paginate(15, ['*'], 'audit_page')->withQueryString(),
            'statistics' => [
                'families' => Family::query()->count(),
                'trees' => FamilyTree::query()->count(),
                'people' => Person::query()->count(),
                'memberships' => FamilyMembership::query()->count(),
                'audit' => AuditLog::query()->count(),
                'active_families' => Family::query()->active()->count(),
                'users' => User::query()->count(),
                'pending_invitations' => FamilyInvitation::query()->whereNull('accepted_at')->whereNull('cancelled_at')->where('expires_at', '>', now())->count(),
            ],
        ]);
    }
}
