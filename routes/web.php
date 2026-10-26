<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\CrossFamilyLinkController;
use App\Http\Controllers\GenealogyGraphController;
use App\Http\Controllers\GenealogyTreeController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\Management\AssertionController;
use App\Http\Controllers\Management\AuditReversionController;
use App\Http\Controllers\Management\BranchController;
use App\Http\Controllers\Management\ChangeRequestController;
use App\Http\Controllers\Management\CsvImportController;
use App\Http\Controllers\Management\FamilyController as ManagementFamilyController;
use App\Http\Controllers\Management\FamilyDashboardController;
use App\Http\Controllers\Management\LookupController;
use App\Http\Controllers\Management\MembershipController;
use App\Http\Controllers\Management\NameReconciliationController;
use App\Http\Controllers\Management\PersonAttributeController;
use App\Http\Controllers\Management\PersonController;
use App\Http\Controllers\Management\PersonMergeController;
use App\Http\Controllers\Management\RecordController;
use App\Http\Controllers\Management\RelationshipController;
use App\Http\Controllers\Management\SourceController;
use App\Http\Controllers\Management\TreeController;
use App\Http\Controllers\SiteAdmin\DashboardController as SiteAdminDashboardController;
use App\Http\Controllers\SiteAdmin\FamilyController as SiteAdminFamilyController;
use App\Http\Controllers\SiteAdmin\FamilyTreeController as SiteAdminFamilyTreeController;
use App\Http\Controllers\SiteAdmin\ResourceController;
use App\Http\Controllers\SiteAdmin\UserController as SiteAdminUserController;
use App\Models\Family;
use Illuminate\Support\Facades\Route;

$data = static fn (string $file): array => require resource_path("data/al-zoubi/{$file}.php");

Route::get('/', fn () => view('juthoor.home', ['family' => $data('family'), 'publicFamilies' => Family::query()->active()->whereHas('familyTrees', fn ($query) => $query->active())->with(['familyTrees' => fn ($query) => $query->active()])->orderBy('name_ar')->get()]))->name('home');
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

Route::prefix('families/{family}')->name('genealogy.')->middleware('throttle:240,1')->group(function () {
    Route::get('/tree', [GenealogyTreeController::class, 'show'])->name('tree');
    Route::get('/tree/branches/{branch}', [GenealogyTreeController::class, 'branch'])->name('branch');
    Route::get('/people/{person}', [GenealogyTreeController::class, 'person'])->whereNumber('person')->name('person');
    Route::get('/graph', [GenealogyGraphController::class, 'show'])->name('graph');
    Route::get('/graph/search', [GenealogyGraphController::class, 'search'])->name('search');
});

Route::get('/shared-ancestry', [CrossFamilyLinkController::class, 'publicIndex'])->middleware('throttle:30,1')->name('shared-ancestry.index');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'email'])->middleware('throttle:6,1')->name('password.email');
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
        Route::get('/manage/cross-family-links', [CrossFamilyLinkController::class, 'index'])->name('cross-links.index');
        Route::get('/manage/cross-family-links/create', [CrossFamilyLinkController::class, 'create'])->name('cross-links.create');
        Route::post('/manage/cross-family-links', [CrossFamilyLinkController::class, 'store'])->name('cross-links.store');
        Route::get('/manage/cross-family-links/{link}', [CrossFamilyLinkController::class, 'show'])->name('cross-links.show');
        Route::put('/manage/cross-family-links/{link}/evidence/{citation}', [CrossFamilyLinkController::class, 'evidence'])->whereNumber('citation')->name('cross-links.evidence');
        Route::post('/manage/cross-family-links/{link}/review', [CrossFamilyLinkController::class, 'review'])->name('cross-links.review');
        Route::post('/manage/cross-family-links/{link}/revalidate', [CrossFamilyLinkController::class, 'revalidate'])->middleware('throttle:20,1')->name('cross-links.revalidate');
        Route::get('/manage/families', [ManagementFamilyController::class, 'index'])->name('management.families.index');
        Route::get('/manage/families/{family}/pages/{section}', [ManagementFamilyController::class, 'show'])->where('section', 'people|relationships|branches|trees|members|invitations|audit')->name('management.sections.index');
        Route::get('/manage/families/{family}/records/{section}/create', [RecordController::class, 'create'])->name('management.records.create');
        Route::get('/manage/families/{family}/records/{section}/{record}', [RecordController::class, 'show'])->whereNumber('record')->name('management.records.show');
        Route::get('/manage/families/{family}/records/{section}/{record}/edit', [RecordController::class, 'edit'])->whereNumber('record')->name('management.records.edit');
        Route::put('/manage/families/{family}/relationships/{relationship}', [RelationshipController::class, 'update'])->whereNumber('relationship')->name('management.relationships.update');
        Route::get('/manage/families/{family}', [ManagementFamilyController::class, 'show'])->name('management.families.show');
        Route::get('/manage/families/{family}/dashboard', FamilyDashboardController::class)->name('management.families.dashboard');
        Route::get('/manage/families/{family}/imports', [CsvImportController::class, 'index'])->name('management.imports.index');
        Route::get('/manage/families/{family}/imports/template', [CsvImportController::class, 'template'])->name('management.imports.template');
        Route::post('/manage/families/{family}/imports', [CsvImportController::class, 'store'])->name('management.imports.store');
        Route::get('/manage/families/{family}/imports/{batch}', [CsvImportController::class, 'show'])->whereNumber('batch')->name('management.imports.show');
        Route::post('/manage/families/{family}/imports/{batch}/revalidate', [CsvImportController::class, 'revalidate'])->whereNumber('batch')->name('management.imports.revalidate');
        Route::post('/manage/families/{family}/imports/{batch}/commit', [CsvImportController::class, 'commit'])->whereNumber('batch')->name('management.imports.commit');
        Route::get('/manage/families/{family}/sources', [SourceController::class, 'index'])->name('management.sources.index');
        Route::post('/manage/families/{family}/sources', [SourceController::class, 'store'])->name('management.sources.store');
        Route::get('/manage/families/{family}/sources/{source}', [SourceController::class, 'show'])->whereNumber('source')->name('management.sources.show');
        Route::put('/manage/families/{family}/sources/{source}', [SourceController::class, 'update'])->whereNumber('source')->name('management.sources.update');
        Route::delete('/manage/families/{family}/sources/{source}', [SourceController::class, 'destroy'])->whereNumber('source')->name('management.sources.destroy');
        Route::get('/manage/families/{family}/sources/{source}/download', [SourceController::class, 'download'])->whereNumber('source')->name('management.sources.download');
        Route::post('/manage/families/{family}/sources/{source}/citations', [SourceController::class, 'cite'])->whereNumber('source')->name('management.sources.cite');
        Route::put('/manage/families/{family}/sources/{source}/citations/{citation}', [SourceController::class, 'updateCitation'])->whereNumber('source')->whereNumber('citation')->name('management.sources.citation.update');
        Route::delete('/manage/families/{family}/sources/{source}/citations/{citation}', [SourceController::class, 'removeCitation'])->whereNumber('source')->whereNumber('citation')->name('management.sources.citation.destroy');
        Route::get('/manage/families/{family}/changes', [ChangeRequestController::class, 'index'])->name('management.changes.index');
        Route::get('/manage/families/{family}/changes/create', [ChangeRequestController::class, 'create'])->name('management.changes.create');
        Route::get('/manage/families/{family}/changes/targets', [ChangeRequestController::class, 'targets'])->name('management.changes.targets');
        Route::post('/manage/families/{family}/changes', [ChangeRequestController::class, 'store'])->name('management.changes.store');
        Route::get('/manage/families/{family}/changes/{change}', [ChangeRequestController::class, 'show'])->whereNumber('change')->name('management.changes.show');
        Route::post('/manage/families/{family}/changes/{change}/decision', [ChangeRequestController::class, 'decide'])->whereNumber('change')->name('management.changes.decide');
        Route::get('/manage/families/{family}/recoveries', [AuditReversionController::class, 'history'])->name('management.recoveries.index');
        Route::get('/manage/audit/{auditLog}/revert', [AuditReversionController::class, 'preview'])->whereNumber('auditLog')->name('management.audit.revert.preview');
        Route::post('/manage/audit/{auditLog}/revert', [AuditReversionController::class, 'store'])->whereNumber('auditLog')->name('management.audit.revert.store');
        Route::get('/manage/audit/{auditLog}', [AuditLogController::class, 'show'])->whereNumber('auditLog')->name('management.audit.show');
        Route::get('/manage/families/{family}/lookups/people', [LookupController::class, 'people'])->name('management.lookups.people');
        Route::get('/manage/families/{family}/lookups/branches', [LookupController::class, 'branches'])->name('management.lookups.branches');

        Route::put('/manage/families/{family}/trees/{treeId}/root', [TreeController::class, 'update'])->whereNumber('treeId')->name('management.trees.update');
        Route::post('/manage/families/{family}/trees', [TreeController::class, 'store'])->name('management.trees.store');
        Route::put('/manage/families/{family}/trees/{treeId}', [TreeController::class, 'details'])->whereNumber('treeId')->name('management.trees.details');
        Route::post('/manage/families/{family}/invitations/{invitation}/resend', [InvitationController::class, 'resend'])->middleware('throttle:20,1')->whereNumber('invitation')->name('invitations.resend');
        Route::post('/manage/families/{family}/people', [PersonController::class, 'store'])->name('management.people.store');
        Route::get('/manage/families/{family}/people/{person}', [PersonController::class, 'show'])->whereNumber('person')->name('management.people.show');
        Route::put('/manage/families/{family}/people/{person}', [PersonController::class, 'update'])->whereNumber('person')->name('management.people.update');
        Route::delete('/manage/families/{family}/people/{person}', [PersonController::class, 'destroy'])->whereNumber('person')->name('management.people.destroy');
        Route::post('/manage/families/{family}/people/{person}/assertions', [AssertionController::class, 'store'])->whereNumber('person')->name('management.assertions.store');
        Route::put('/manage/families/{family}/assertions/{assertion}', [AssertionController::class, 'update'])->whereNumber('assertion')->name('management.assertions.update');
        Route::post('/manage/families/{family}/assertions/{assertion}/propose', [AssertionController::class, 'propose'])->whereNumber('assertion')->name('management.assertions.propose');
        Route::post('/manage/families/{family}/assertions/{assertion}/citations', [AssertionController::class, 'cite'])->whereNumber('assertion')->name('management.assertions.cite');
        Route::post('/manage/families/{family}/assertions/{assertion}/decline', [AssertionController::class, 'decline'])->whereNumber('assertion')->name('management.assertions.decline');
        Route::get('/manage/families/{family}/reconciliation', [NameReconciliationController::class, 'index'])->name('management.reconciliation.index');
        Route::get('/manage/families/{family}/merges', [PersonMergeController::class, 'index'])->name('management.merges.index');
        Route::get('/manage/families/{family}/merges/preview', [PersonMergeController::class, 'preview'])->name('management.merges.preview');
        Route::post('/manage/families/{family}/merges', [PersonMergeController::class, 'store'])->name('management.merges.store');
        Route::get('/manage/families/{family}/merges/{merge}', [PersonMergeController::class, 'show'])->whereNumber('merge')->name('management.merges.show');
        Route::post('/manage/families/{family}/merges/{merge}/undo', [PersonMergeController::class, 'undo'])->whereNumber('merge')->name('management.merges.undo');
        Route::post('/manage/families/{family}/relationships', [RelationshipController::class, 'store'])->name('management.relationships.store');
        Route::delete('/manage/families/{family}/relationships/{relationship}', [RelationshipController::class, 'destroy'])->whereNumber('relationship')->name('management.relationships.destroy');
        Route::post('/manage/families/{family}/branches', [BranchController::class, 'store'])->name('management.branches.store');
        Route::put('/manage/families/{family}/branches/{branch}', [BranchController::class, 'update'])->whereNumber('branch')->name('management.branches.update');
        Route::delete('/manage/families/{family}/branches/{branch}', [BranchController::class, 'destroy'])->whereNumber('branch')->name('management.branches.destroy');
        Route::post('/manage/families/{family}/memberships', [MembershipController::class, 'store'])->name('management.memberships.store');
        Route::put('/manage/families/{family}/memberships/{membership}', [MembershipController::class, 'update'])->whereNumber('membership')->name('management.memberships.update');
        Route::delete('/manage/families/{family}/memberships/{membership}', [MembershipController::class, 'destroy'])->whereNumber('membership')->name('management.memberships.destroy');
        Route::put('/manage/families/{family}/invitations/{invitation}', [InvitationController::class, 'update'])->whereNumber('invitation')->name('invitations.update');
        Route::post('/manage/families/{family}/invitations', [InvitationController::class, 'store'])->middleware('throttle:20,1')->name('invitations.store');
        Route::delete('/manage/families/{family}/invitations/{invitation}', [InvitationController::class, 'cancel'])->whereNumber('invitation')->name('invitations.cancel');

        Route::post('/site-admin/users', [SiteAdminUserController::class, 'store'])->name('site-admin.users.store');
        Route::get('/site-admin/{resource}', [ResourceController::class, 'index'])->where('resource', 'families|trees|users|memberships|invitations|audit')->name('site-admin.resources.index');
        Route::get('/site-admin/{resource}/create', [ResourceController::class, 'create'])->where('resource', 'families|trees|users|memberships|invitations')->name('site-admin.resources.create');
        Route::get('/site-admin/{resource}/{record}', [ResourceController::class, 'show'])->where('resource', 'families|trees|users|memberships|invitations|audit')->whereNumber('record')->name('site-admin.resources.show');
        Route::get('/site-admin/{resource}/{record}/edit', [ResourceController::class, 'edit'])->where('resource', 'families|trees|users|memberships|invitations')->whereNumber('record')->name('site-admin.resources.edit');
        Route::get('/site-admin/person-attributes', [PersonAttributeController::class, 'index'])->name('site-admin.attributes.index');
        Route::get('/site-admin/person-attributes/create', [PersonAttributeController::class, 'create'])->name('site-admin.attributes.create');
        Route::post('/site-admin/person-attributes', [PersonAttributeController::class, 'store'])->name('site-admin.attributes.store');
        Route::get('/site-admin/person-attributes/{attribute}/edit', [PersonAttributeController::class, 'edit'])->whereNumber('attribute')->name('site-admin.attributes.edit');
        Route::put('/site-admin/person-attributes/{attribute}', [PersonAttributeController::class, 'update'])->whereNumber('attribute')->name('site-admin.attributes.update');
        Route::get('/manage/families/{family}/person-attributes', [PersonAttributeController::class, 'index'])->name('management.attributes.index');
        Route::get('/manage/families/{family}/person-attributes/create', [PersonAttributeController::class, 'create'])->name('management.attributes.create');
        Route::post('/manage/families/{family}/person-attributes', [PersonAttributeController::class, 'store'])->name('management.attributes.store');
        Route::get('/manage/families/{family}/person-attributes/{attribute}/edit', [PersonAttributeController::class, 'edit'])->whereNumber('attribute')->name('management.attributes.edit');
        Route::put('/manage/families/{family}/person-attributes/{attribute}', [PersonAttributeController::class, 'update'])->whereNumber('attribute')->name('management.attributes.update');
        Route::get('/site-admin', SiteAdminDashboardController::class)->name('site-admin.dashboard');
        Route::post('/site-admin/families', [SiteAdminFamilyController::class, 'store'])->name('site-admin.families.store');
        Route::put('/site-admin/families/{family}', [SiteAdminFamilyController::class, 'update'])->name('site-admin.families.update');
        Route::post('/site-admin/families/{family}/trees', [SiteAdminFamilyTreeController::class, 'store'])->name('site-admin.trees.store');
        Route::put('/site-admin/families/{family}/trees/{tree}', [SiteAdminFamilyTreeController::class, 'update'])->whereNumber('tree')->name('site-admin.trees.update');
        Route::put('/site-admin/users/{user}', [SiteAdminUserController::class, 'update'])->whereNumber('user')->name('site-admin.users.update');
        Route::delete('/site-admin/users/{user}', [SiteAdminUserController::class, 'destroy'])->whereNumber('user')->name('site-admin.users.destroy');
    });
});
