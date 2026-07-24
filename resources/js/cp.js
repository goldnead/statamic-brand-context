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
    Statamic.$components.register('brand-switcher', BrandSwitcher);
    Statamic.$components.append('brand-switcher');
});
