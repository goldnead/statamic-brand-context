/**
 * Brand Context — Statamic 6 Control Panel entry point.
 *
 * Registers a global brand switcher and appends it to every CP page, so it
 * floats in the top-right of the header area (the CP has no addon slot for the
 * native user menu / topbar, so an appended global component is the supported
 * way to place a global control there). Switching navigates with ?brand=<handle>,
 * which the SetBrandFromSession middleware resolves + persists.
 */
import { config } from '@statamic/cms/api';

import BrandSwitcher from './BrandSwitcher.vue';
import Users from './pages/Users.vue';
import Settings from './pages/Settings.vue';

Statamic.booting(() => {
    // The switcher is multi-brand only, and the flag comes from the server
    // (ServiceProvider::registerControlPanel). Since the settings screen made
    // this bundle load on single-brand installs too, an unconditional append
    // would put a switcher with nothing to switch on every one of them — and
    // BrandSwitcher would log "the middleware is missing" on a site where
    // nothing is missing.
    if (config.get('brandContextMultiBrand')) {
        // Guard: a switcher failure must never crash the whole CP (it runs
        // inside Statamic.start()). append() requires an options object (it
        // destructures `props` from it), so pass one even though we take no
        // props.
        try {
            Statamic.$components.register('brand-switcher', BrandSwitcher);
            Statamic.$components.append('brand-switcher', { props: {} });
        } catch (e) {
            console.error('[brand-context] brand switcher failed to mount:', e);
        }
    }

    // The membership screen. The identifier must match the Inertia::render()
    // call in BrandUserController exactly.
    Statamic.$inertia.register('brand-context::Users', Users);

    // The suite's settings screen. Registered in both modes, like the route
    // and the nav item — a single-brand install has settings too.
    Statamic.$inertia.register('brand-context::Settings', Settings);
});
