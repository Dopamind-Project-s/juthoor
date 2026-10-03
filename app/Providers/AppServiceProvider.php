<?php

namespace App\Providers;

use App\Models\Family;
use App\Models\User;
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
        Gate::before(function (User $user): ?bool {
            return $user->isSuperAdmin() ? true : null;
        });
        View::composer('layouts.management', function ($view): void {
            $user = auth()->user();
            $families = ! $user ? collect() : ($user->isSuperAdmin()
                ? Family::query()->orderBy('name_ar')->get(['name_ar', 'slug'])
                : $user->families()->orderBy('name_ar')->get(['families.name_ar', 'families.slug']));
            $view->with('accessibleFamilies', $families);
        });
    }
}
