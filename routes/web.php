<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'pages.home')->name('home');
Route::view('/ui', 'pages.ui')->name('ui');
Route::view('/families', 'pages.families')->name('families.index');
Route::view('/families/al-zoubi', 'pages.family')->name('family.show');
Route::view('/families/al-zoubi/branches', 'pages.branches')->name('family.branches');
Route::view('/families/al-zoubi/tree', 'trees.master')->name('family.tree');
Route::view('/families/al-zoubi/branches/al-awdat/tree', 'trees.branch')->name('branch.tree');
Route::view('/people/1/family', 'trees.person')->name('person.family');
