/**
 * Brand Context — Statamic 6 Control Panel entry point.
 *
 * Registers a global brand switcher and appends it to every CP page, so it
 * floats in the top-right of the header area (the CP has no addon slot for the
 * native user menu / topbar, so an appended global component is the supported
 * way to place a global control there). Switching navigates with ?brand=<handle>,
 * which the SetBrandFromSession middleware resolves + persists.
 */
import BrandSwitcher from './BrandSwitcher.vue';

Statamic.booting(() => {
    // Guard: a switcher failure must never crash the whole CP (it runs inside
    // Statamic.start()). append() requires an options object (it destructures
    // `props` from it), so pass one even though we take no props.
    try {
        Statamic.$components.register('brand-switcher', BrandSwitcher);
        Statamic.$components.append('brand-switcher', { props: {} });
    } catch (e) {
        console.error('[brand-context] brand switcher failed to mount:', e);
    }
});
