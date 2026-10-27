<?php

namespace App\Services\Portal;

use App\Http\Middleware\ResolveFamilyHost;
use App\Models\CommunityEntity;
use App\Models\Family;
use App\Services\Community\CommunityHierarchy;

/**
 * The family website frame shared by the landing page and every public family page (entities, directory, heritage):
 * published branding, approved palette, public logo, canonical address and navigation. Reads only published settings
 * and public images; computed when the layout renders (once per page).
 */
class FamilySiteChrome
{
    public function __construct(private readonly PortalSettings $settings, private readonly FamilyHosts $hosts, private readonly CommunityHierarchy $hierarchy) {}

    /**
     * @param  array<string, mixed>|null  $branding  draft branding for a preview; published otherwise
     * @return array<string, mixed>
     */
    public function for(Family $family, ?array $branding = null): array
    {
        $branding ??= $this->settings->published($family);
        $palette = config('portals.accents')[$branding['accent']] ?? config('portals.accents')['heritage'];
        $tree = $family->familyTrees()->active()->where('is_primary', true)->first() ?? $family->familyTrees()->active()->orderBy('id')->first();
        $publicIds = $this->hierarchy->publicIds($family->id);
        $root = $publicIds === [] ? null : CommunityEntity::query()->whereIn('id', $publicIds)->whereNull('parent_id')->orderBy('sort_order')->orderBy('id')->first();
        $portalUrl = $this->hosts->portalUrl($family);
        $nav = array_filter([
            'home' => ['label' => 'الرئيسية', 'url' => $portalUrl, 'icon' => 'house'],
            'tree' => $tree ? ['label' => 'الشجرة', 'url' => route('genealogy.tree', [$family->slug, 'tree' => $tree->slug]), 'icon' => 'diagram-3'] : null,
            'entities' => $publicIds ? ['label' => 'الأفخاذ والفروع', 'url' => route('community.entities.index', $family->slug), 'icon' => 'diagram-2'] : null,
            'heritage' => $root ? ['label' => 'التاريخ والتراث', 'url' => route('community.entities.show', [$family->slug, $root->slug]).'#history', 'icon' => 'hourglass-split'] : null,
            'news' => $root ? ['label' => 'الأخبار', 'url' => route('community.entities.news', [$family->slug, $root->slug]), 'icon' => 'megaphone'] : null,
            'directory' => ['label' => 'الدليل', 'url' => route('directory.index', $family->slug), 'icon' => 'person-vcard'],
        ]);

        return [
            'branding' => $branding,
            'palette' => $palette,
            'logo' => $this->settings->publicImage($family, $branding['logo_media'] ?? null),
            'portalUrl' => $portalUrl,
            'siteTree' => $tree,
            'siteNav' => $nav,
            'onFamilyHost' => request()->attributes->has(ResolveFamilyHost::ATTRIBUTE),
        ];
    }
}
