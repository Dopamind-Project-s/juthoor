<?php

namespace App\Providers;

use App\Models\Family;
use App\Models\User;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::useBootstrapFive();
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
