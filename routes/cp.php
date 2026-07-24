<?php

use Goldnead\BrandContext\Http\Controllers\Cp\BrandSwitcherController;
use Illuminate\Support\Facades\Route;

// Mounted by the ServiceProvider inside the CP route group, which applies the
// `cp` prefix and the `brand-context.` name prefix. Keep names relative.
Route::get('brands', [BrandSwitcherController::class, 'index'])->name('switcher.index');
Route::post('brands/switch', [BrandSwitcherController::class, 'switch'])->name('switcher.switch');
