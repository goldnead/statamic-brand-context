<?php

namespace Goldnead\BrandContext\Http\Controllers\Cp;

use Goldnead\BrandContext\Http\Middleware\SetBrandFromSession;
use Goldnead\BrandContext\Models\Brand;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Minimal CP brand switcher. Lists brands and writes the chosen one into the
 * session; SetBrandFromSession picks it up on subsequent requests. Only
 * reachable when multi-brand is active (routes registered conditionally).
 */
class BrandSwitcherController extends Controller
{
    public function index()
    {
        return view('brand-context::cp.switcher', [
            'brands' => Brand::query()->orderByDesc('is_default')->orderBy('name')->get(),
            'currentId' => app('brand-context')->currentId(),
        ]);
    }

    public function switch(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'brand_id' => ['required', 'integer', 'exists:brands,id'],
        ]);

        $request->session()->put(SetBrandFromSession::SESSION_KEY, (int) $validated['brand_id']);

        return back()->with('success', __('Brand switched.'));
    }
}
