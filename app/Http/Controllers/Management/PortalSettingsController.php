<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Models\MediaItem;
use App\Services\Community\MediaAccess;
use App\Services\Genealogy\SelectedFamilyTree;
use App\Services\Portal\FamilyHosts;
use App\Services\Portal\PortalSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** Family website branding (draft → preview → publish) and domain requests, for holders of family.settings. */
class PortalSettingsController extends Controller
{
    public function __construct(private readonly PortalSettings $settings, private readonly FamilyHosts $hosts) {}

    public function edit(Request $request, string $family): View
    {
        $familyModel = $this->family($family);
        $images = MediaItem::query()->where('family_id', $familyModel->id)->where('publication_status', MediaItem::STATUS_PUBLISHED)->where('audience', 'public')
            ->with('ownerEntity')->orderByDesc('id')->limit(100)->get()
            ->filter(fn (MediaItem $media) => $media->isImage() && app(MediaAccess::class)->canView(null, $media))->values();

        return view('management.portal.edit', [
            'family' => $familyModel,
            'tree' => app(SelectedFamilyTree::class)->resolve($familyModel),
            'draft' => $this->settings->draft($familyModel),
            'setting' => $familyModel->portalSetting,
            'images' => $images,
            'entities' => $this->settings->entityOptions($familyModel),
            'domains' => $familyModel->domains()->orderByDesc('id')->get(),
            'portalUrl' => $this->hosts->portalUrl($familyModel),
            'suffix' => config('portals.subdomain_suffix'),
        ]);
    }

    public function update(Request $request, string $family): RedirectResponse
    {
        $familyModel = $this->family($family);
        $input = $request->except(['_token', '_method']);
        $input['sections'] = collect($request->input('sections', []))->map(fn ($row, $key) => ['key' => $key, 'visible' => (bool) ($row['visible'] ?? false), 'order' => (int) ($row['order'] ?? 0)])
            ->sortBy('order')->map(fn (array $row) => ['key' => $row['key'], 'visible' => $row['visible']])->values()->all();
        $input['contact_links'] = collect($request->input('contact_links', []))->filter(fn ($row) => filled($row['label'] ?? null) || filled($row['url'] ?? null))->values()->all();
        $this->settings->saveDraft($request->user(), $familyModel, $input);

        return redirect()->route('management.portal.edit', $familyModel->slug)->with('status', 'حُفظت المسودة. راجع المعاينة ثم انشرها؛ الزوار يرون النسخة المنشورة فقط.');
    }

    public function publish(Request $request, string $family): RedirectResponse
    {
        $familyModel = $this->family($family);
        $this->settings->publish($request->user(), $familyModel);

        return back()->with('status', 'نُشرت هوية موقع العائلة وسُجّل ذلك في التدقيق.');
    }

    public function requestDomain(Request $request, string $family): RedirectResponse
    {
        $familyModel = $this->family($family);
        $data = $request->validate(['host' => ['required', 'string', 'max:253']]);
        $this->hosts->request($request->user(), $familyModel, $data['host']);

        return back()->with('status', 'سُجّل طلب النطاق. لا يعمل قبل تحقق مشرف المنصة من DNS وTLS وتفعيله.');
    }

    public function withdrawDomain(Request $request, string $family, int $domain): RedirectResponse
    {
        $familyModel = $this->family($family);
        $this->hosts->withdraw($request->user(), $familyModel->domains()->findOrFail($domain));

        return back()->with('status', 'سُحب طلب النطاق.');
    }

    private function family(string $slug): Family
    {
        $family = Family::query()->where('slug', $slug)->firstOrFail();
        Gate::authorize('update', $family);

        return $family;
    }
}
