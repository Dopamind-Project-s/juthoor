<?php

namespace App\Http\Controllers\SiteAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Family;
use App\Models\FamilyInvitation;
use App\Models\FamilyMembership;
use App\Models\FamilyTree;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ResourceController extends Controller
{
    public const RESOURCES = [
        'families' => [Family::class, 'العائلات', ['name_ar', 'slug']],
        'trees' => [FamilyTree::class, 'الأشجار', ['name_ar', 'slug']],
        'users' => [User::class, 'المستخدمون', ['name', 'email', 'username']],
        'memberships' => [FamilyMembership::class, 'العضويات', ['role']],
        'invitations' => [FamilyInvitation::class, 'الدعوات', ['email', 'role']],
        'audit' => [AuditLog::class, 'سجل التدقيق', ['action', 'auditable_type']],
    ];

    public function index(Request $request, string $resource): View
    {
        [$model, $title, $columns] = $this->resource($resource);
        $query = $model::query();
        if ($request->filled('q')) {
            $query->where(function ($query) use ($request, $columns, $resource): void {
                foreach ($columns as $column) {
                    $query->orWhere($column, 'like', '%'.$request->string('q').'%');
                }
                if ($resource === 'memberships') {
                    $query->orWhereHas('user', fn ($user) => $user->where('name', 'like', '%'.$request->string('q').'%')->orWhere('email', 'like', '%'.$request->string('q').'%'));
                }
            });
        }
        if (in_array($resource, ['trees', 'memberships', 'invitations', 'audit'])) {
            $query->with('family');
            if ($request->filled('family_id')) {
                $query->where('family_id', $request->integer('family_id'));
            }
        }
        if (in_array($resource, ['memberships', 'audit'])) {
            $query->with('user');
        }
        if (in_array($resource, ['families', 'trees', 'users']) && in_array($request->query('active'), ['0', '1'], true)) {
            $query->where('is_active', $request->query('active'));
        }
        if (in_array($resource, ['memberships', 'invitations']) && $request->filled('role')) {
            $query->where('role', $request->query('role'));
        }
        if ($resource === 'invitations') {
            match ($request->query('status')) {
                'accepted' => $query->whereNotNull('accepted_at'),
                'cancelled' => $query->whereNotNull('cancelled_at'),
                'expired' => $query->whereNull('accepted_at')->whereNull('cancelled_at')->where('expires_at', '<=', now()),
                'pending' => $query->whereNull('accepted_at')->whereNull('cancelled_at')->where('expires_at', '>', now()),
                default => null,
            };
        }

        return view('site-admin.resources.index', [
            'resource' => $resource, 'title' => $title,
            'records' => $query->latest('id')->paginate(15)->withQueryString(),
            'families' => Family::query()->orderBy('name_ar')->get(),
        ]);
    }

    public function create(Request $request, string $resource): View
    {
        return $this->page($request, $resource, null, 'create');
    }

    public function show(Request $request, string $resource, int $record): View
    {
        return $this->page($request, $resource, $record, 'show');
    }

    public function edit(Request $request, string $resource, int $record): View
    {
        return $this->page($request, $resource, $record, 'edit');
    }

    private function resource(string $resource): array
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403);
        abort_unless(isset(self::RESOURCES[$resource]), 404);

        return self::RESOURCES[$resource];
    }

    private function page(Request $request, string $resource, ?int $id, string $mode): View
    {
        [$model, $title] = $this->resource($resource);
        abort_if($resource === 'audit' && $mode !== 'show', 404);
        $record = $id ? $model::query()->findOrFail($id) : null;
        $selectedFamily = $record?->family ?? ($request->filled('family_id') ? Family::query()->findOrFail($request->integer('family_id')) : null);

        return view('site-admin.resources.page', [
            'resource' => $resource, 'title' => $title, 'record' => $record, 'mode' => $mode,
            'selectedFamily' => $selectedFamily,
            'families' => Family::query()->orderBy('name_ar')->get(),
        ]);
    }
}
