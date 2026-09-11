<?php

use Illuminate\Support\Facades\Route;

$data = static fn (string $file): array => require resource_path("data/al-zoubi/{$file}.php");

Route::view('/', 'juthoor.home', ['family' => $data('family')])->name('home');
Route::view('/ui', 'pages.ui')->name('ui');
Route::redirect('/families/al-zoubi', '/al-zoubi');
Route::redirect('/families/al-zoubi/branches', '/al-zoubi/branches');
Route::redirect('/families/al-zoubi/tree', '/al-zoubi/tree');

Route::prefix('al-zoubi')->name('family.')->group(function () use ($data) {
    Route::view('/', 'families.al-zoubi.home', [
        'family' => $data('family'), 'genealogy' => $data('genealogy'), 'branches' => $data('branches'),
        'history' => $data('history'), 'people' => $data('people'), 'archive' => $data('archive'),
    ])->name('home');
    Route::view('/tree', 'families.al-zoubi.tree', ['genealogy' => $data('genealogy'), 'branches' => $data('branches')])->name('tree');
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

Route::view('/people/1/family', 'trees.person')->name('person.family');
