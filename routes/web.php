<?php

use Illuminate\Support\Facades\Route;

// ── Public web page (Blade welcome view) ──────────────────────────────────
Route::get('/', function () {
    return view('welcome');
});

// ── Named login route ──────────────────────────────────────────────────────
// Filament requires a named 'login' route to exist on the web guard.
// Without this you get: Route [login] not defined.
// Filament registers its OWN /admin/login page, but it looks up this
// named route when redirecting unauthenticated users.
// This redirect sends API consumers to the correct place.
Route::get('/login', function () {
    return redirect('/admin/login');
})->name('login');
