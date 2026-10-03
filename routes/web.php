<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\GenealogyGraphController;
use App\Http\Controllers\GenealogyTreeController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\Management\BranchController;
use App\Http\Controllers\Management\FamilyController as ManagementFamilyController;
use App\Http\Controllers\Management\LookupController;
use App\Http\Controllers\Management\MembershipController;
use App\Http\Controllers\Management\PersonController;
use App\Http\Controllers\Management\RelationshipController;
use App\Http\Controllers\SiteAdmin\DashboardController as SiteAdminDashboardController;
use App\Http\Controllers\SiteAdmin\FamilyController as SiteAdminFamilyController;
use App\Http\Controllers\SiteAdmin\FamilyTreeController as SiteAdminFamilyTreeController;
use App\Http\Controllers\SiteAdmin\UserController as SiteAdminUserController;
use Illuminate\Support\Facades\Route;

$data = static fn (string $file): array => require resource_path("data/al-zoubi/{$file}.php");

Route::view('/', 'juthoor.home', ['family' => $data('family')])->name('home');
Route::view('/ui', 'pages.ui')->name('ui');
Route::redirect('/families/al-zoubi', '/al-zoubi');
Route::redirect('/families/al-zoubi/branches', '/al-zoubi/branches');
Route::redirect('/al-zoubi/tree', '/families/al-zoubi/tree')->name('family.tree');

Route::prefix('al-zoubi')->name('family.')->group(function () use ($data) {
    Route::view('/', 'families.al-zoubi.home', [
        'family' => $data('family'), 'genealogy' => $data('genealogy'), 'branches' => $data('branches'),
        'history' => $data('history'), 'people' => $data('people'), 'archive' => $data('archive'),
    ])->name('home');
    Route::view('/history', 'families.al-zoubi.history', ['history' => $data('history')])->name('history');
    Route::view('/branches', 'families.al-zoubi.branches.index', ['branches' => $data('branches')])->name('branches');

    foreach ($data('branches') as $branch) {
        Route::view("/branches/{$branch['slug']}", 'families.al-zoubi.branches.show', ['branch' => $branch])->name('branches.'.$branch['slug']);
        foreach ($branch['children'] as $child) {
            Route::view("/branches/{$child['slug']}", 'families.al-zoubi.branches.show', ['branch' => $child, 'parent' => $branch])->name('branches.'.str_replace('/', '.', $child['slug']));
        }
    }

    Route::redirect('/branches/al-awdat', '/al-zoubi/branches/al-awtalat');
    Route::view('/archive', 'families.al-zoubi.archive', ['archive' => $data('archive')])->name('archive');
    Route::view('/achievements', 'families.al-zoubi.achievements')->name('achievements');
    Route::view('/prominent-people', 'families.al-zoubi.prominent-people', ['people' => $data('people')])->name('people');
    Route::view('/members', 'families.al-zoubi.members', ['branches' => $data('branches')])->name('members');
});

Route::prefix('families/{family}')->name('genealogy.')->group(function () {
    Route::get('/tree', [GenealogyTreeController::class, 'show'])->name('tree');
    Route::get('/tree/branches/{branch}', [GenealogyTreeController::class, 'branch'])->name('branch');
    Route::get('/people/{person}', [GenealogyTreeController::class, 'person'])->whereNumber('person')->name('person');
    Route::get('/graph', [GenealogyGraphController::class, 'show'])->name('graph');
    Route::get('/graph/search', [GenealogyGraphController::class, 'search'])->name('search');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'email'])->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'reset'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->name('password.update');
    Route::get('/invitations/{token}/register', [RegisteredUserController::class, 'create'])->name('invitations.register');
    Route::post('/invitations/{token}/register', [RegisteredUserController::class, 'store'])->name('invitations.register.store');
});

Route::get('/invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::get('/verify-email', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('/verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])->middleware('signed')->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'send'])->middleware('throttle:6,1')->name('verification.send');
    Route::post('/invitations/{token}/accept', [InvitationController::class, 'accept'])->name('invitations.accept');

    Route::middleware('verified')->group(function () {
        Route::get('/manage/families', [ManagementFamilyController::class, 'index'])->name('management.families.index');
        Route::get('/manage/families/{family}', [ManagementFamilyController::class, 'show'])->name('management.families.show');
        Route::get('/manage/audit/{auditLog}', [AuditLogController::class, 'show'])->name('management.audit.show');
        Route::get('/manage/families/{family}/lookups/people', [LookupController::class, 'people'])->name('management.lookups.people');
        Route::get('/manage/families/{family}/lookups/branches', [LookupController::class, 'branches'])->name('management.lookups.branches');

        Route::post('/manage/families/{family}/people', [PersonController::class, 'store'])->name('management.people.store');
        Route::get('/manage/families/{family}/people/{person}', [PersonController::class, 'show'])->name('management.people.show');
        Route::put('/manage/families/{family}/people/{person}', [PersonController::class, 'update'])->name('management.people.update');
        Route::delete('/manage/families/{family}/people/{person}', [PersonController::class, 'destroy'])->name('management.people.destroy');
        Route::post('/manage/families/{family}/relationships', [RelationshipController::class, 'store'])->name('management.relationships.store');
        Route::delete('/manage/families/{family}/relationships/{relationship}', [RelationshipController::class, 'destroy'])->name('management.relationships.destroy');
        Route::post('/manage/families/{family}/branches', [BranchController::class, 'store'])->name('management.branches.store');
        Route::put('/manage/families/{family}/branches/{branch}', [BranchController::class, 'update'])->name('management.branches.update');
        Route::delete('/manage/families/{family}/branches/{branch}', [BranchController::class, 'destroy'])->name('management.branches.destroy');
        Route::post('/manage/families/{family}/memberships', [MembershipController::class, 'store'])->name('management.memberships.store');
        Route::put('/manage/families/{family}/memberships/{membership}', [MembershipController::class, 'update'])->name('management.memberships.update');
        Route::delete('/manage/families/{family}/memberships/{membership}', [MembershipController::class, 'destroy'])->name('management.memberships.destroy');
        Route::post('/manage/families/{family}/invitations', [InvitationController::class, 'store'])->name('invitations.store');
        Route::delete('/manage/families/{family}/invitations/{invitation}', [InvitationController::class, 'cancel'])->name('invitations.cancel');

        Route::get('/site-admin', SiteAdminDashboardController::class)->name('site-admin.dashboard');
        Route::post('/site-admin/families', [SiteAdminFamilyController::class, 'store'])->name('site-admin.families.store');
        Route::put('/site-admin/families/{family}', [SiteAdminFamilyController::class, 'update'])->name('site-admin.families.update');
        Route::post('/site-admin/families/{family}/trees', [SiteAdminFamilyTreeController::class, 'store'])->name('site-admin.trees.store');
        Route::put('/site-admin/families/{family}/trees/{tree}', [SiteAdminFamilyTreeController::class, 'update'])->name('site-admin.trees.update');
        Route::put('/site-admin/users/{user}', [SiteAdminUserController::class, 'update'])->name('site-admin.users.update');
        Route::delete('/site-admin/users/{user}', [SiteAdminUserController::class, 'destroy'])->name('site-admin.users.destroy');
    });
});
