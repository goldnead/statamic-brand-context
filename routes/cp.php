<?php

use Goldnead\BrandContext\Http\Controllers\Cp\BrandSwitcherController;
use Illuminate\Support\Facades\Route;

Route::get('brands', [BrandSwitcherController::class, 'index'])->name('brand-context.switcher.index');
Route::post('brands/switch', [BrandSwitcherController::class, 'switch'])->name('brand-context.switcher.switch');
