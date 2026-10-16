<?php

namespace App\Providers;

use App\Models\Citation;
use App\Models\CrossFamilyLink;
use App\Models\Family;
use App\Models\FamilyTree;
use App\Models\Person;
use App\Models\PersonAttribute;
use App\Models\PersonRelationship;
use App\Models\Source;
use App\Models\User;
use App\Services\Genealogy\CrossFamilyLinkService;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::useBootstrapFive();
        foreach ([Person::class, PersonRelationship::class, FamilyTree::class, Family::class, Source::class, Citation::class, PersonAttribute::class] as $model) {
            $invalidate = function (): void {
                if (! Schema::hasTable('cross_family_links')) {
                    return;
                }
                foreach (CrossFamilyLink::query()->where('status', 'approved')->get() as $link) {
                    app(CrossFamilyLinkService::class)->current($link);
                }
            };
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
        View::composer('layouts.management', function ($view): void {
            $user = auth()->user();
            $families = ! $user ? collect() : ($user->isSuperAdmin()
                ? Family::query()->orderBy('name_ar')->get(['name_ar', 'slug'])
                : $user->families()->active()->orderBy('name_ar')->get(['families.name_ar', 'families.slug']));
            $view->with('accessibleFamilies', $families);
        });
    }
}
