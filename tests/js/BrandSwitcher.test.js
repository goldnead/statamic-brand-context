import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import BrandSwitcher from '../../resources/js/BrandSwitcher.vue';
import { __setConfig } from './stubs/api.js';

const brands = [
    { id: 1, name: 'Acme', handle: 'acme' },
    { id: 2, name: 'Umbrella', handle: 'umbrella' },
];

function withHeader() {
    document.body.innerHTML = '<header><div id="nav"></div><div id="cluster"></div></header>';

    return document.querySelector('#cluster');
}

beforeEach(() => {
    document.body.innerHTML = '';
    __setConfig({ brandContext: { brands, current: 1 } });
});

afterEach(() => {
    document.body.innerHTML = '';
});

describe('BrandSwitcher', () => {
    it('teleports into the last cluster of the Control Panel header', async () => {
        const cluster = withHeader();

        mount(BrandSwitcher, { attachTo: document.body });
        await nextTick();

        expect(cluster.textContent).toContain('Acme');
    });

    // The switcher is placed by querying core's own header markup, because the
    // Control Panel exposes no slot for a global addon control. If that markup
    // ever changes the query returns nothing — and a multi-brand user losing the
    // only way to change brand is worse than a switcher in the wrong place.
    it('renders in place when the header cluster is missing', () => {
        document.body.innerHTML = '<div id="app"></div>';

        const wrapper = mount(BrandSwitcher, { attachTo: '#app' });

        expect(wrapper.text()).toContain('Acme');
        expect(wrapper.find('[data-stub="Dropdown"]').exists()).toBe(true);
    });

    it('renders nothing when there is only one brand', () => {
        __setConfig({ brandContext: { brands: [brands[0]], current: 1 } });
        withHeader();

        const wrapper = mount(BrandSwitcher, { attachTo: document.body });

        expect(wrapper.find('[data-stub="Dropdown"]').exists()).toBe(false);
    });

    // One config path, and it says so when it fails. The previous four-fallback
    // version turned "the data never arrived" into a silently absent control.
    it('logs an error and renders nothing when the config never arrived', () => {
        __setConfig({});
        const error = vi.spyOn(console, 'error').mockImplementation(() => {});
        withHeader();

        const wrapper = mount(BrandSwitcher, { attachTo: document.body });

        expect(error).toHaveBeenCalledOnce();
        expect(error.mock.calls[0][0]).toContain('[brand-context]');
        expect(wrapper.find('[data-stub="Dropdown"]').exists()).toBe(false);
    });

    it('offers every brand and marks the current one', () => {
        withHeader();

        const wrapper = mount(BrandSwitcher, { attachTo: document.body });
        const items = wrapper.findAll('[data-stub="DropdownItem"]');

        expect(items).toHaveLength(2);
        expect(items[0].attributes('data-icon')).toBe('checkmark');
        expect(items[1].attributes('data-icon')).toBeUndefined();
    });

    it('labels itself through the lang namespace, not an English literal', () => {
        withHeader();

        const wrapper = mount(BrandSwitcher, { attachTo: document.body });

        expect(wrapper.find('[data-stub="Button"]').attributes('aria-label'))
            .toBe('brand-context::messages.switcher_aria_label');
        expect(wrapper.find('[data-stub="DropdownLabel"]').text())
            .toContain('brand-context::messages.switcher_label');
    });

    it('navigates to the chosen brand with ?brand=<handle>', async () => {
        withHeader();
        const assign = vi.fn();
        const original = Object.getOwnPropertyDescriptor(window, 'location');
        delete window.location;
        window.location = { href: 'https://example.test/cp/collections?page=2' };
        Object.defineProperty(window.location, 'href', {
            get: () => 'https://example.test/cp/collections?page=2',
            set: assign,
            configurable: true,
        });

        const wrapper = mount(BrandSwitcher, { attachTo: document.body });
        await wrapper.findAll('[data-stub="DropdownItem"]')[1].trigger('click');

        expect(assign).toHaveBeenCalledWith('https://example.test/cp/collections?page=2&brand=umbrella');

        if (original) Object.defineProperty(window, 'location', original);
    });
});
