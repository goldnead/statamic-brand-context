import { describe, it, expect, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import Settings from '../../resources/js/pages/Settings.vue';
import { router, toggleArchitecturalBackground } from './stubs/inertia.js';

const brand = { id: 1, name: 'Acme', handle: 'acme' };

const section = {
    namespace: 'automations',
    title: 'Automations',
    config_path: 'automations',
    groups: [
        {
            title: 'Runs',
            description: 'How much of each run is kept.',
            fields: [
                { key: 'label', type: 'string', label: 'Label', nullable: false },
                { key: 'retention.days', type: 'integer', label: 'Retention', nullable: true, min: 1 },
                { key: 'enabled', type: 'boolean', label: 'Enabled', nullable: false },
                { key: 'redact_keys', type: 'list', label: 'Redacted keys', nullable: false },
            ],
        },
    ],
    values: {
        'label': 'packaged',
        'retention.days': 30,
        'enabled': true,
        'redact_keys': ['authorization', 'x-api-key'],
    },
};

function props(overrides = {}) {
    return {
        brand,
        multiBrand: false,
        sections: [section],
        updateUrl: '/cp/brand-settings',
        addonsUrl: '/cp/addons',
        ...overrides,
    };
}

beforeEach(() => {
    router.patch.mockClear();
    toggleArchitecturalBackground.mockClear();
});

describe('Suite settings screen', () => {
    it('offers a way forward instead of an empty box when no addon registers settings', () => {
        const wrapper = mount(Settings, { props: props({ sections: [] }) });

        expect(wrapper.find('[data-brand-settings-empty]').exists()).toBe(true);
        // The item is the point: the first version of this screen passed a
        // `description` to EmptyStateMenu, which has no such prop, and rendered
        // a narrow card with a blank bar under it and no text at all.
        expect(wrapper.find('[data-stub="EmptyStateItem"]').attributes('href')).toBe('/cp/addons');
        expect(wrapper.find('[data-stub="Header"]').exists()).toBe(false);
    });

    it('puts the architectural lines behind the empty state and nowhere else', () => {
        mount(Settings, { props: props({ sections: [] }) });
        expect(toggleArchitecturalBackground).toHaveBeenLastCalledWith(true);

        toggleArchitecturalBackground.mockClear();

        // A full page of forms on a decorated ground reads as a treatment
        // nobody chose. The first version called the argument-less
        // `useArchitecturalBackground()` with a getter, which was ignored, and
        // the lines stayed on behind every section.
        mount(Settings, { props: props() });
        expect(toggleArchitecturalBackground).toHaveBeenLastCalledWith(false);
    });

    it('says why instead of offering a Save that cannot work', () => {
        // What an upgrade without migrations looks like. Reading still works,
        // so the page keeps showing the packaged values; offering Save would
        // hand the operator an SQL error for doing what the screen invited.
        const wrapper = mount(Settings, { props: props({ writable: false }) });

        expect(wrapper.find('[data-brand-settings-readonly]').exists()).toBe(true);
        expect(wrapper.find('[data-settings-save="automations"]').exists()).toBe(false);
        expect(wrapper.findAll('[data-settings-field^="automations:"]')).toHaveLength(4);
    });

    it('draws one section per registered addon, from its own groups', () => {
        const wrapper = mount(Settings, { props: props() });

        expect(wrapper.find('[data-brand-settings-section="automations"]').exists()).toBe(true);
        expect(wrapper.findAll('[data-settings-field^="automations:"]')).toHaveLength(4);
        expect(wrapper.find('[data-brand-settings-empty]').exists()).toBe(false);
    });

    it('names the brand only when there is more than one', () => {
        expect(mount(Settings, { props: props() }).find('[data-brand-settings-brand]').exists()).toBe(false);

        const multi = mount(Settings, { props: props({ multiBrand: true }) });

        expect(multi.find('[data-brand-settings-brand]').exists()).toBe(true);
        expect(multi.text()).toContain('Acme');
    });

    it('keeps the save button disabled until something actually changed', async () => {
        const wrapper = mount(Settings, { props: props() });

        expect(wrapper.find('[data-settings-save="automations"]').attributes('disabled')).toBeDefined();

        await wrapper.find('[data-settings-field="automations:label"] input').setValue('changed');

        expect(wrapper.find('[data-settings-save="automations"]').attributes('disabled')).toBeUndefined();
    });

    it('posts through Inertia, names its namespace, and turns the textarea back into a list', async () => {
        const wrapper = mount(Settings, { props: props() });

        await wrapper.find('[data-settings-field="automations:label"] input').setValue('changed');
        await wrapper.find('[data-settings-save="automations"]').trigger('click');

        expect(router.patch).toHaveBeenCalledOnce();

        const [url, payload] = router.patch.mock.calls[0];

        expect(url).toBe('/cp/brand-settings');
        expect(payload.namespace).toBe('automations');
        expect(payload.settings.label).toBe('changed');
        // Edited as lines, sent as an array — the server never has to guess a
        // separator, and a trailing blank line is not a stored empty entry.
        expect(payload.settings.redact_keys).toEqual(['authorization', 'x-api-key']);
    });

    it('shows a validation error on the field it belongs to', async () => {
        const wrapper = mount(Settings, { props: props() });

        await wrapper.find('[data-settings-field="automations:label"] input').setValue('changed');
        await wrapper.find('[data-settings-save="automations"]').trigger('click');

        router.patch.mock.calls[0][2].onError({ 'settings.label': ['A label is required.'] });
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-settings-field-error="automations:label"]').text())
            .toBe('A label is required.');
        expect(wrapper.find('[data-settings-form-errors="automations"]').exists()).toBe(false);
    });

    it('surfaces an error that belongs to no field instead of swallowing it', async () => {
        const wrapper = mount(Settings, { props: props() });

        await wrapper.find('[data-settings-field="automations:label"] input').setValue('changed');
        await wrapper.find('[data-settings-save="automations"]').trigger('click');

        router.patch.mock.calls[0][2].onError({ 'settings.gone_from_the_screen': ['Nope.'] });
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-settings-form-errors="automations"]').text()).toContain('Nope.');
    });

    it('does not wipe unsaved edits in a section that was not the one saved', async () => {
        const other = { ...section, namespace: 'leadhub', title: 'LeadHub', config_path: 'leadhub' };
        const wrapper = mount(Settings, { props: props({ sections: [section, other] }) });

        // Two sections open, edits in both, only one saved. The save reloads
        // the whole page, and the reload used to refill every form.
        await wrapper.find('[data-settings-field="automations:label"] input').setValue('automations edit');
        await wrapper.find('[data-settings-field="leadhub:label"] input').setValue('leadhub edit');
        await wrapper.find('[data-settings-save="automations"]').trigger('click');

        await wrapper.setProps({ sections: [{ ...section }, { ...other }] });

        expect(wrapper.find('[data-settings-field="automations:label"] input').element.value)
            .toBe('packaged');
        expect(wrapper.find('[data-settings-field="leadhub:label"] input').element.value)
            .toBe('leadhub edit');
    });

    it('wipes every form when the brand changed, even the edited ones', async () => {
        const wrapper = mount(Settings, { props: props({ multiBrand: true }) });

        await wrapper.find('[data-settings-field="automations:label"] input').setValue('typed under Acme');

        // The switcher moved. Showing one brand's typed values under another
        // brand's name is the one thing this screen must never do.
        await wrapper.setProps({
            brand: { id: 2, name: 'Other', handle: 'other' },
            sections: [{ ...section }],
        });

        expect(wrapper.find('[data-settings-field="automations:label"] input').element.value)
            .toBe('packaged');
    });

    it('takes the server version of what it just saved, not what was typed', async () => {
        const wrapper = mount(Settings, { props: props() });

        await wrapper.find('[data-settings-field="automations:label"] input').setValue('changed');
        await wrapper.find('[data-settings-save="automations"]').trigger('click');

        // What the redirect does: same component, new props. The server may
        // answer with something other than what was typed — a nullable field
        // blanked comes back null, a number comes back a number, a value equal
        // to the packaged default comes back as the default with the override
        // deleted. The form has to take the answer.
        await wrapper.setProps({
            sections: [{ ...section, values: { ...section.values, label: 'from the server' } }],
        });

        expect(wrapper.find('[data-settings-field="automations:label"] input').element.value)
            .toBe('from the server');
        expect(wrapper.find('[data-settings-save="automations"]').attributes('disabled')).toBeDefined();
    });
});
