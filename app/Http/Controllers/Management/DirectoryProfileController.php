<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\CommunityEntity;
use App\Models\DirectoryReport;
use App\Models\Family;
use App\Models\MemberProfile;
use App\Services\Community\CommunityHierarchy;
use App\Services\Community\MemberDirectory;
use App\Services\Genealogy\SelectedFamilyTree;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** A member's own directory profile, and the family administrators' review of affiliations and reports. */
class DirectoryProfileController extends Controller
{
    public function __construct(private readonly MemberDirectory $directory) {}

    public function edit(Request $request, string $family): View
    {
        $familyModel = $this->family($family);
        abort_unless($this->directory->canHaveProfile($request->user(), $familyModel), 403);

        return view('management.directory.edit', [
            'family' => $familyModel, 'tree' => app(SelectedFamilyTree::class)->resolve($familyModel),
            'profile' => MemberProfile::query()->where('user_id', $request->user()->id)->where('family_id', $familyModel->id)->with('entity:id,name_ar')->first() ?? new MemberProfile(['display_name' => $request->user()->name, 'audience' => 'members']),
            'entities' => app(CommunityHierarchy::class)->ordered(CommunityEntity::query()->where('family_id', $familyModel->id)->get()),
        ]);
    }

    public function update(Request $request, string $family): RedirectResponse
    {
        $familyModel = $this->family($family);
        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:120'], 'entity_id' => ['nullable', 'integer'],
            'profession' => ['nullable', 'string', 'max:255'], 'specialization' => ['nullable', 'string', 'max:255'],
            'sector' => ['nullable', Rule::in(array_keys(MemberProfile::SECTORS))], 'skills' => ['nullable', 'string', 'max:1000'],
            'experience' => ['nullable', 'string', 'max:2000'], 'organization' => ['nullable', 'string', 'max:255'],
            'business_name' => ['nullable', 'string', 'max:255'], 'business_category' => ['nullable', Rule::in(array_keys(MemberProfile::BUSINESS_CATEGORIES))],
            'services' => ['nullable', 'string', 'max:2000'], 'governorate' => ['nullable', 'string', 'max:120'], 'city' => ['nullable', 'string', 'max:120'],
            'links' => ['nullable', 'string', 'max:2000'], 'collaboration' => ['nullable', 'string', 'max:2000'],
            'contact_email' => ['nullable', 'email', 'max:255'], 'contact_phone' => ['nullable', 'string', 'regex:/^\+?[0-9 ()-]{6,20}$/'],
            'audience' => ['required', Rule::in(['members', 'public'])],
            'visibility' => ['nullable', 'array'], 'visibility.*' => [Rule::in(array_keys(MemberProfile::LEVELS))],
            'is_listed' => ['nullable', 'boolean'], 'photo' => ['nullable', 'file'], 'remove_photo' => ['nullable', 'boolean'],
        ]);
        $split = fn (?string $value) => collect(preg_split('/[\r\n،,]+/u', (string) $value))->map(fn (string $item) => trim($item))->filter()->unique()->values();
        $skills = $split($data['skills'] ?? null)->map(fn (string $skill) => mb_substr($skill, 0, 60))->take(20)->all();
        $links = $split($data['links'] ?? null)->take(5)->all();
        foreach ($links as $link) {
            if (! filter_var($link, FILTER_VALIDATE_URL) || ! preg_match('#^https?://#i', $link)) {
                throw ValidationException::withMessages(['links' => 'كل رابط يجب أن يبدأ بـ http أو https.']);
            }
        }
        $fields = collect($data)->except(['visibility', 'is_listed', 'photo', 'remove_photo'])->all();
        $profile = $this->directory->save($request->user(), $familyModel, [...$fields, 'skills' => $skills ?: null, 'links' => $links ?: null],
            array_intersect_key($data['visibility'] ?? [], MemberProfile::FIELDS), $request->boolean('is_listed'), $request->file('photo'), $request->boolean('remove_photo'));

        if ($profile->suspended_at) {
            return back()->with('status', 'حُفظ ملفك، لكن ظهوره ما زال موقوفًا حتى مراجعة إدارة الدليل.');
        }

        return back()->with('status', $request->boolean('is_listed') ? 'حُفظ ملفك وهو ظاهر في الدليل وفق إعدادات الخصوصية.' : 'حُفظ ملفك وهو غير ظاهر في الدليل.');
    }

    public function destroy(Request $request, string $family): RedirectResponse
    {
        $familyModel = $this->family($family);
        $profile = MemberProfile::query()->where('user_id', $request->user()->id)->where('family_id', $familyModel->id)->firstOrFail();
        $this->directory->delete($request->user(), $profile);

        return redirect()->route('directory.index', $familyModel->slug)->with('status', 'حُذف ملفك من الدليل. لم يتغير حسابك ولا أي سجل في الشجرة.');
    }

    public function review(Request $request, string $family): View
    {
        $familyModel = $this->family($family);
        Gate::authorize('moderateDirectory', $familyModel);
        $profiles = MemberProfile::query()->where('family_id', $familyModel->id);

        return view('management.directory.review', [
            'family' => $familyModel, 'tree' => app(SelectedFamilyTree::class)->resolve($familyModel),
            // Administrators see the claim and the display name only, never the member's private fields.
            'pending' => (clone $profiles)->where('affiliation_status', 'pending')->with('entity:id,name_ar', 'user:id,name')->paginate(15, ['id', 'user_id', 'display_name', 'entity_id', 'affiliation_status'], 'affiliations_page')->withQueryString(),
            'reports' => DirectoryReport::query()->where('status', 'open')->whereIn('member_profile_id', (clone $profiles)->select('id'))->with('profile:id,display_name', 'reporter:id,name')->latest('id')->paginate(15, ['*'], 'reports_page')->withQueryString(),
            'history' => DirectoryReport::query()->where('status', '!=', 'open')->whereIn('member_profile_id', (clone $profiles)->select('id'))->with('profile:id,display_name')->latest('resolved_at')->paginate(15, ['*'], 'history_page')->withQueryString(),
            'suspended' => (clone $profiles)->whereNotNull('suspended_at')->paginate(15, ['id', 'display_name', 'suspended_at', 'suspension_note'], 'suspended_page')->withQueryString(),
        ]);
    }

    public function affiliation(Request $request, string $family, int $profile): RedirectResponse
    {
        $familyModel = $this->family($family);
        $data = $request->validate(['decision' => ['required', Rule::in(['verified', 'rejected'])], 'note' => ['nullable', 'string', 'max:1000']]);
        $this->directory->reviewAffiliation($request->user(), MemberProfile::query()->where('family_id', $familyModel->id)->findOrFail($profile), $data['decision'], $data['note'] ?? null);

        return back()->with('status', 'سُجّل قرار الانتماء.');
    }

    public function resolve(Request $request, string $family, int $report): RedirectResponse
    {
        $familyModel = $this->family($family);
        $data = $request->validate(['action' => ['required', Rule::in(['dismiss', 'suspend'])], 'note' => ['nullable', 'string', 'max:1000']]);
        $reportModel = DirectoryReport::query()->whereHas('profile', fn ($profiles) => $profiles->where('family_id', $familyModel->id))->where('status', 'open')->findOrFail($report);
        $this->directory->resolveReport($request->user(), $reportModel, $data['action'], $data['note'] ?? null);

        return back()->with('status', 'عولج البلاغ.');
    }

    public function reinstate(Request $request, string $family, int $profile): RedirectResponse
    {
        $familyModel = $this->family($family);
        $this->directory->reinstate($request->user(), MemberProfile::query()->where('family_id', $familyModel->id)->findOrFail($profile));

        return back()->with('status', 'أُعيد إظهار الملف.');
    }

    private function family(string $slug): Family
    {
        return Family::query()->active()->where('slug', $slug)->firstOrFail();
    }
}
