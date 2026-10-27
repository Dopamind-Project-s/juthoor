<?php

namespace App\Providers;

use App\Models\Citation;
use App\Models\Family;
use App\Models\FamilyTree;
use App\Models\Person;
use App\Models\PersonAttribute;
use App\Models\PersonRelationship;
use App\Models\Source;
use App\Models\User;
use App\Services\Dashboards\FamilyDashboardService;
use App\Services\Genealogy\CrossFamilyLinkInvalidator;
use App\Services\Portal\FamilySiteChrome;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CrossFamilyLinkInvalidator::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::useBootstrapFive();
        foreach ([Person::class, PersonRelationship::class, FamilyTree::class, Family::class, Source::class, Citation::class, PersonAttribute::class] as $model) {
            $invalidate = fn ($record) => app(CrossFamilyLinkInvalidator::class)->changed($record);
            $model::saved($invalidate);
            $model::deleted($invalidate);
        }
        Gate::before(function (User $user, string $ability, array $arguments): ?bool {
            if (! $user->is_active) {
                return false;
            }
            if (! $user->isSuperAdmin() && isset($arguments[0]) && $arguments[0] instanceof Family && ! $arguments[0]->is_active) {
                return false;
            }

            return $user->isSuperAdmin() ? true : null;
        });
        // Family website frame: every page extending layouts.family-site gets the family's published (or previewed) branding.
        // Child views render their sections before the layout, so they receive the frame data too.
        View::composer(['layouts.family-site', 'community.index', 'community.show', 'community.diwans.show', 'community.news.*', 'community.events.*', 'directory.*'], function ($view): void {
            $data = $view->getData();
            if (! isset($data['siteNav']) && isset($data['family'])) {
                $view->with(app(FamilySiteChrome::class)->for($data['family'], $data['previewBranding'] ?? null));
            }
        });
        View::composer('layouts.management', function ($view): void {
            $view->with('accessibleFamilies', FamilyDashboardService::accessibleFamilies(auth()->user()));
        });
    }
}
