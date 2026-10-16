<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Services\Genealogy\SelectedFamilyTree;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class RecordController extends Controller
{
    public function create(Request $request, string $family, string $section): View
    {
        return $this->page($family, $section, null, 'create');
    }

    public function show(Request $request, string $family, string $section, int $record): View
    {
        return $this->page($family, $section, $record, 'show');
    }

    public function edit(Request $request, string $family, string $section, int $record): View
    {
        return $this->page($family, $section, $record, 'edit');
    }

    private function page(string $slug, string $section, ?int $id, string $mode): View
    {
        $family = Family::query()->where('slug', $slug)->firstOrFail();
        Gate::authorize('view', $family);
        $tree = app(SelectedFamilyTree::class)->resolve($family);
        $title = ['people' => 'الأشخاص', 'relationships' => 'العلاقات', 'branches' => 'الفروع', 'trees' => 'الأشجار', 'members' => 'الأعضاء', 'invitations' => 'الدعوات'][$section] ?? null;
        abort_unless($title, 404);
        $isAdmin = auth()->user()->isSuperAdmin() || auth()->user()->roleFor($family) === 'admin';
        $canEdit = in_array($section, ['trees', 'members', 'invitations']) ? $isAdmin : auth()->user()->canEditFamily($family);
        if (in_array($section, ['members', 'invitations'])) {
            Gate::authorize('manageMembers', $family);
        }
        abort_if($mode !== 'show' && ! $canEdit, 403);
        $query = match ($section) {
            'people' => $tree->people(),
            'relationships' => $tree->personRelationships(),
            'branches' => $tree->branches(),
            'trees' => $family->familyTrees(),
            'members' => $family->memberships(),
            'invitations' => $family->invitations(),
        };
        $record = $id ? $query->findOrFail($id) : null;

        return view('management.records.page', compact('family', 'tree', 'section', 'title', 'mode', 'record', 'canEdit', 'isAdmin'));
    }
}
