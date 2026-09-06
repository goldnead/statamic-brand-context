<?php

use Goldnead\BrandContext\Http\Controllers\Cp\BrandSettingsController;
use Illuminate\Support\Facades\Route;

/**
 * The suite's settings screen.
 *
 * Separate from `cp.php` because that file is only pushed under multi-brand —
 * it holds the membership screen, which has no meaning on an install with one
 * brand. Settings do.
 *
 * The brand these routes act on is always the current one, taken from the
 * session in multi-brand mode and the default one otherwise. It is
 * deliberately not a route parameter: a URL that names a brand is a URL that
 * can be edited, and the whole isolation argument for this screen rests on the
 * request having no say in which brand it writes.
 */
Route::prefix('brand-settings')->name('brand-context.settings.')->group(function () {
    Route::get('/', [BrandSettingsController::class, 'index'])->name('index');
    Route::patch('/', [BrandSettingsController::class, 'update'])->name('update');
});
