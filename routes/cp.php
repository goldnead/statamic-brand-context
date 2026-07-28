<?php

use Goldnead\BrandContext\Http\Controllers\Cp\BrandUserController;
use Illuminate\Support\Facades\Route;

/**
 * Control Panel routes. Registered only when multi-brand is active — a
 * single-brand install has one brand and nothing to assign anybody to.
 *
 * The brand these routes act on is always the current one, taken from the
 * session by SetBrandFromSession. It is deliberately not a route parameter:
 * a URL that names a brand is a URL that can be edited.
 */
Route::prefix('brands')->name('brand-context.')->group(function () {
    Route::get('users', [BrandUserController::class, 'index'])->name('users.index');
    Route::post('users', [BrandUserController::class, 'store'])->name('users.store');
    Route::delete('users', [BrandUserController::class, 'destroy'])->name('users.destroy');
});
