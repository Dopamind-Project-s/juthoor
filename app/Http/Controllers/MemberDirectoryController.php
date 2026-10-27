<?php

namespace App\Http\Controllers;

use App\Models\CommunityEntity;
use App\Models\DirectoryReport;
use App\Models\Family;
use App\Models\MemberProfile;
use App\Services\Community\CommunityHierarchy;
use App\Services\Community\MemberDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The public face of the voluntary directory. Pages render only the fields the viewer may read and are excluded from
 * search engines; profiles that are not listed, suspended or out of the viewer's audience answer 404.
 */
class MemberDirectoryController extends Controller
{
    public function __construct(private readonly MemberDirectory $directory) {}

    public function index(Request $request, string $family): Response
    {
        $familyModel = Family::query()->active()->where('slug', $family)->firstOrFail();
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'profession' => ['nullable', 'string', 'max:100'], 'skill' => ['nullable', 'string', 'max:60'],
            'sector' => ['nullable', Rule::in(array_keys(MemberProfile::SECTORS))], 'city' => ['nullable', 'string', 'max:120'],
            'business_category' => ['nullable', Rule::in(array_keys(MemberProfile::BUSINESS_CATEGORIES))], 'entity' => ['nullable', 'integer'],
            'service' => ['nullable', 'string', 'max:100'], 'collaboration' => ['nullable', 'string', 'max:100'],
            'verified' => ['nullable', 'boolean'], 'layout' => ['nullable', Rule::in(['grid', 'list'])],
            'scope' => ['nullable', Rule::in(['direct', 'descendants'])],
        ]);
        $level = $this->directory->viewerLevel($request->user(), $familyModel);
        $fieldsColumn = $level === 'members' ? 'member_fields' : 'public_fields';
        $entityIds = $level === 'members' ? CommunityEntity::query()->where('family_id', $familyModel->id)->pluck('id')->all() : app(CommunityHierarchy::class)->publicIds($familyModel->id);

        return response()->view('directory.index', [
            'family' => $familyModel, 'filters' => $filters, 'level' => $level,
            'categories' => $this->directory->categories($request->user(), $familyModel),
            'visibleFields' => fn (MemberProfile $profile) => $this->directory->visibleFields($request->user(), $profile),
            'profiles' => $this->directory->search($request->user(), $familyModel, $filters)->with(['entity:id,name_ar', 'family'])->paginate(20)->withQueryString(),
            // Filter choices come only from values the audience may already read.
            'cities' => $this->directory->listed($familyModel, $level)->where($fieldsColumn, 'like', '%|city|%')->whereNotNull('city')->distinct()->orderBy('city')->pluck('city'),
            'entities' => CommunityEntity::query()->whereIn('id', $entityIds)->orderBy('name_ar')->get(['id', 'name_ar']),
            'ownProfile' => $request->user() ? MemberProfile::query()->where('user_id', $request->user()->id)->where('family_id', $familyModel->id)->first() : null,
            'canHaveProfile' => $request->user() && $this->directory->canHaveProfile($request->user(), $familyModel),
        ])->header('X-Robots-Tag', 'noindex, nofollow')->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, string $family, int $profile): Response
    {
        [$familyModel, $profileModel] = $this->profile($request, $family, $profile);

        return response()->view('directory.show', [
            'family' => $familyModel, 'profile' => $profileModel, 'fields' => $this->directory->visibleFields($request->user(), $profileModel),
            'isOwner' => $request->user()?->id === $profileModel->user_id,
        ])->header('X-Robots-Tag', 'noindex, nofollow')->header('Cache-Control', 'private, no-store');
    }

    public function photo(Request $request, string $family, int $profile): StreamedResponse
    {
        [, $profileModel] = $this->profile($request, $family, $profile);
        abort_unless($profileModel->photo_path && in_array('photo', $this->directory->visibleFields($request->user(), $profileModel), true), 404);
        abort_unless(Storage::disk($profileModel->photo_disk)->exists($profileModel->photo_path), 404);

        return Storage::disk($profileModel->photo_disk)->response($profileModel->photo_path, 'photo.'.pathinfo($profileModel->photo_path, PATHINFO_EXTENSION), [
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox", 'Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex',
        ]);
    }

    public function contact(Request $request, string $family, int $profile): RedirectResponse
    {
        [, $profileModel] = $this->profile($request, $family, $profile);
        $data = $request->validate(['message' => ['nullable', 'string', 'max:1000']]);
        $this->directory->contact($request->user(), $profileModel, $data['message'] ?? null);

        return back()->with('status', 'أُرسل طلب التواصل داخل المنصة دون كشف بياناتك أو بياناته.');
    }

    public function report(Request $request, string $family, int $profile): RedirectResponse
    {
        [, $profileModel] = $this->profile($request, $family, $profile);
        $data = $request->validate(['reason' => ['required', Rule::in(array_keys(DirectoryReport::REASONS))], 'details' => ['nullable', 'string', 'max:2000']]);
        $this->directory->report($request->user(), $profileModel, $data['reason'], $data['details'] ?? null);

        return back()->with('status', 'شكرًا؛ وصل البلاغ إلى إدارة العائلة.');
    }

    /** @return array{Family, MemberProfile} */
    private function profile(Request $request, string $family, int $id): array
    {
        $familyModel = Family::query()->active()->where('slug', $family)->firstOrFail();
        $profile = MemberProfile::query()->where('family_id', $familyModel->id)->whereKey($id)->with('entity:id,name_ar', 'family', 'user')->firstOrFail();
        abort_unless($this->directory->canView($request->user(), $profile), 404);

        return [$familyModel, $profile];
    }
}
