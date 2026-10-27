<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveFamilyHost;
use App\Models\Family;
use App\Services\Portal\FamilyPortalPage;
use App\Services\Portal\PortalSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** Family websites: on the platform path during migration, and on the family's own host once it is active. */
class FamilyPortalController extends Controller
{
    public function __construct(private readonly FamilyPortalPage $page, private readonly PortalSettings $settings) {}

    /** "/" — the family website on a family host, the platform home everywhere else. */
    public function home(Request $request): View|Response
    {
        if ($family = $request->attributes->get(ResolveFamilyHost::ATTRIBUTE)) {
            return $this->render($request, $family, $this->settings->published($family), false);
        }

        return view('juthoor.home', [
            'family' => require resource_path('data/al-zoubi/family.php'),
            'publicFamilies' => Family::query()->active()->whereHas('familyTrees', fn ($query) => $query->active())->with(['familyTrees' => fn ($query) => $query->active()])->orderBy('name_ar')->get(),
        ]);
    }

    public function show(Request $request, string $family): Response
    {
        $familyModel = Family::query()->active()->where('slug', $family)->firstOrFail();

        return $this->render($request, $familyModel, $this->settings->published($familyModel), false);
    }

    /** The draft as visitors would see it, for administrators only; never indexed or cached. */
    public function preview(Request $request, string $family): Response
    {
        $familyModel = Family::query()->where('slug', $family)->firstOrFail();
        Gate::authorize('update', $familyModel);

        return $this->render($request, $familyModel, $this->settings->draft($familyModel), true)
            ->header('X-Robots-Tag', 'noindex, nofollow')->header('Cache-Control', 'private, no-store');
    }

    /** @param array<string, mixed> $branding */
    private function render(Request $request, Family $family, array $branding, bool $preview): Response
    {
        // Public data rules apply to everyone on the landing page, including signed-in members and the preview.
        $data = $this->page->build($family, $branding, null);

        return response()->view('portal.home', $data + ['preview' => $preview, 'onFamilyHost' => $request->attributes->has(ResolveFamilyHost::ATTRIBUTE)]);
    }
}
