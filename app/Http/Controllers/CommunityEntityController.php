<?php

namespace App\Http\Controllers;

use App\Models\CommunityEntity;
use App\Models\EntityAchievement;
use App\Models\Family;
use App\Services\Community\CommunityEntityPage;
use App\Services\Community\CommunityHierarchy;
use App\Services\Community\EntityContentScope;
use App\Services\Community\EntityDirectory;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Public tribe, clan, fakhd and branch pages. A page is public when it and every ancestor are published; managers of the
 * entity may preview anything else with `preview=1`, which also lists unpublished child entities.
 */
class CommunityEntityController extends Controller
{
    public function __construct(private readonly CommunityHierarchy $hierarchy) {}

    public function index(Request $request, string $family, EntityDirectory $directory): View
    {
        $familyModel = Family::query()->active()->where('slug', $family)->firstOrFail();
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:80'], 'type' => ['nullable', 'string', 'max:30'], 'within' => ['nullable', 'integer']]);

        return view('community.index', ['family' => $familyModel, 'filters' => $filters, ...$directory->build($familyModel, $filters)]);
    }

    public function show(Request $request, string $family, string $entity, CommunityEntityPage $page): View
    {
        $familyModel = Family::query()->active()->where('slug', $family)->firstOrFail();
        $entityModel = CommunityEntity::query()->where('family_id', $familyModel->id)->where('slug', $entity)->firstOrFail();
        $preview = $request->boolean('preview') && $request->user()?->hasVerifiedEmail() && $request->user()->can('update', $entityModel);
        abort_unless($preview || in_array($entityModel->id, $this->hierarchy->publicIds($familyModel->id), true), 404);
        $scope = array_key_exists((string) $request->query('scope'), EntityContentScope::MODES) ? (string) $request->query('scope') : EntityContentScope::WITH_DESCENDANTS;

        return view('community.show', [
            'family' => $familyModel,
            'entity' => $entityModel,
            'scope' => $scope,
            'preview' => $preview,
            'category' => array_key_exists((string) $request->query('category'), EntityAchievement::CATEGORIES) ? (string) $request->query('category') : null,
            // Members of the owning family also see records addressed to members.
            ...$page->build($entityModel, $scope, ! $preview, $request->user()),
        ]);
    }
}
