<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'juthoor.home')->name('home');
Route::view('/ui', 'pages.ui')->name('ui');
Route::redirect('/families/al-zoubi', '/al-zoubi');
Route::redirect('/families/al-zoubi/branches', '/al-zoubi/branches');
Route::redirect('/families/al-zoubi/tree', '/al-zoubi/tree');
Route::prefix('al-zoubi')->name('family.')->group(function () {
    Route::view('/', 'families.al-zoubi.home')->name('home');
    Route::view('/tree', 'families.al-zoubi.tree')->name('tree');
    Route::view('/history', 'families.al-zoubi.history')->name('history');
    Route::view('/branches', 'families.al-zoubi.branches.index')->name('branches');
    Route::view('/branches/al-awdat', 'families.al-zoubi.branches.show')->name('branches.show');
    Route::view('/archive', 'families.al-zoubi.archive')->name('archive');
    Route::view('/achievements', 'families.al-zoubi.achievements')->name('achievements');
    Route::view('/prominent-people', 'families.al-zoubi.prominent-people')->name('people');
    Route::view('/members', 'families.al-zoubi.members')->name('members');
});
Route::view('/people/1/family', 'trees.person')->name('person.family');
