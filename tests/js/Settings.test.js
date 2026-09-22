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

const other = { ...section, namespace: 'leadhub', title: 'LeadHub', config_path: 'leadhub' };

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

/** Click a tab the way an operator does, rather than reaching into the state. */
const openTab = (wrapper, namespace) =>
    wrapper.find(`[data-brand-settings-tab="${namespace}"]`).trigger('click');

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

    it('nennt ein Addon, das sich nicht anmelden konnte', () => {
        // Die Registry ueberspringt eine fehlerhafte Anmeldung, damit ein
        // einzelnes Addon nicht die ganze Installation mitnimmt. Genau deshalb
        // muss der Ausfall hier stehen: ein Abschnitt, der einfach fehlt, faellt
        // niemandem auf. Der Absturz fiel wenigstens auf.
        const wrapper = mount(Settings, {
            props: props({ failures: [{ addon: 'Goldnead\\Nachbar\\Settings', reason: 'Class not found' }] }),
        });

        const alert = wrapper.find('[data-brand-settings-failures]');

        expect(alert.exists()).toBe(true);
        expect(alert.text()).toContain('Goldnead\\Nachbar\\Settings');
        expect(alert.text()).toContain('Class not found');
    });

    it('meldet den Ausfall auch dann, wenn kein Abschnitt uebrig bleibt', () => {
        // Sind alle Abschnitte ausgefallen, ist `sections` leer — und der
        // Leerzustand wuerde behaupten, kein Addon melde Einstellungen an. Sie
        // melden an, sie kommen nur nicht durch.
        const wrapper = mount(Settings, {
            props: props({ sections: [], failures: [{ addon: 'Nachbar', reason: 'Class not found' }] }),
        });

        expect(wrapper.find('[data-brand-settings-empty]').exists()).toBe(false);
        expect(wrapper.find('[data-brand-settings-failures]').exists()).toBe(true);
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

    it('shows one addon at a time instead of all of them under each other', async () => {
        // Der Grund fuer das ganze Ticket: zweiundzwanzig Addons, rund neunzig
        // Feldgruppen, bis zum 22.09.2026 alle untereinander auf einer Seite.
        const wrapper = mount(Settings, { props: props({ sections: [section, other] }) });

        expect(wrapper.findAll('[data-brand-settings-tab]')).toHaveLength(2);
        expect(wrapper.find('[data-brand-settings-section="automations"]').exists()).toBe(true);
        expect(wrapper.find('[data-brand-settings-section="leadhub"]').exists()).toBe(false);

        await openTab(wrapper, 'leadhub');

        expect(wrapper.find('[data-brand-settings-section="automations"]').exists()).toBe(false);
        expect(wrapper.find('[data-brand-settings-section="leadhub"]').exists()).toBe(true);
    });

    it('opens the tab the server was asked for, not the first one', () => {
        // Das ist die Haelfte, an der der Seitenleisten-Eintrag je Addon
        // haengt. `statamic-automations` hat seinen alten Settings-Kindeintrag
        // 2026 entfernt, weil ein Menuepunkt, der nur weiterleitet, als Bug
        // gemeldet wurde. Ohne das hier ist der Eintrag genau dieser Bug.
        const wrapper = mount(Settings, {
            props: props({ sections: [section, other], initialSection: 'leadhub' }),
        });

        expect(wrapper.find('[data-brand-settings-section="leadhub"]').exists()).toBe(true);
        expect(wrapper.find('[data-brand-settings-tab="leadhub"]').attributes('data-active')).toBe('true');
    });

    it('falls back to the first tab instead of showing nothing under a full tab bar', () => {
        // Ein veralteter Lesezeichen-Link, ein Addon, das deinstalliert wurde,
        // ein von Hand getippter Namensraum. Der Server faengt das ab, und die
        // Seite faengt es ein zweites Mal: `Tabs` mit einem `modelValue`, zu
        // dem es keinen `TabContent` gibt, zeigt eine leere Seite unter einer
        // vollen Tableiste — ein weisser Bildschirm fuer einen Tippfehler.
        const wrapper = mount(Settings, {
            props: props({ sections: [section, other], initialSection: 'gibt-es-nicht' }),
        });

        expect(wrapper.find('[data-brand-settings-section="automations"]').exists()).toBe(true);
    });

    it('keeps the address bar on the open tab, so a save comes back to it', async () => {
        // Speichern leitet auf den Referrer zurueck. Ohne das hier landet wer
        // `payments` aus der Seitenleiste oeffnet, auf `invoices` wechselt und
        // speichert, wieder auf `payments` — und der Speichervorgang sieht aus,
        // als waere er nicht passiert.
        // Wie Inertia den Eintrag hinterlaesst, auf dem die Seite steht.
        window.history.replaceState({ inertia: 'die serialisierte Seite' }, '', '/cp/brand-settings');

        const wrapper = mount(Settings, { props: props({ sections: [section, other] }) });

        await openTab(wrapper, 'leadhub');

        expect(new URL(window.location.href).searchParams.get('section')).toBe('leadhub');
        // Inertia haelt seine serialisierte Seite in `history.state`. Wird die
        // beim Ersetzen weggeworfen, ist der Zurueck-Knopf kaputt.
        expect(window.history.state).toEqual({ inertia: 'die serialisierte Seite' });
    });

    it('follows a second click in the sidebar', async () => {
        // Wer schon auf der Seite steht und in der Seitenleiste ein anderes
        // Addon anklickt, macht einen Inertia-Besuch auf dieselbe Komponente
        // mit einem anderen `?section=`: neue Props, dieselbe Instanz, und von
        // allein aendert sich im DOM nichts. Genau das waere der Menuepunkt,
        // der aussieht wie ein Link und nirgendwohin fuehrt.
        const wrapper = mount(Settings, {
            props: props({ sections: [section, other], initialSection: 'automations' }),
        });

        await wrapper.setProps({ initialSection: 'leadhub' });

        expect(wrapper.find('[data-brand-settings-section="leadhub"]').exists()).toBe(true);
    });

    it('does not drag an operator off the tab they picked themselves', async () => {
        // Speichern leitet zurueck und rendert neu. Der Anfangswert aendert
        // sich dabei nicht — und wenn er sich nicht aendert, darf er auch
        // nichts umstellen, sonst spraenge die Seite nach jedem Speichern auf
        // den Tab aus der Seitenleiste zurueck.
        const wrapper = mount(Settings, {
            props: props({ sections: [section, other], initialSection: 'automations' }),
        });

        await openTab(wrapper, 'leadhub');
        await wrapper.setProps({ sections: [{ ...section }, { ...other }] });

        expect(wrapper.find('[data-brand-settings-section="leadhub"]').exists()).toBe(true);
    });

    it('leaves out a tab bar of one', () => {
        // Ein einzelner Tab ist eine Beschriftung ueber der Ueberschrift, die
        // sie wiederholt. Auf einer Installation mit einem Addon ist das die
        // ganze Leiste.
        const wrapper = mount(Settings, { props: props() });

        expect(wrapper.find('[data-brand-settings-tabs]').exists()).toBe(false);
        expect(wrapper.find('[data-brand-settings-section="automations"]').exists()).toBe(true);
    });

    it('has no tab for an addon the server left out', () => {
        // Die Rechtepruefung sitzt im Controller, und was sie aussortiert,
        // kommt hier gar nicht erst an. Der Punkt des Tests ist, dass die
        // Tableiste aus `sections` gebaut wird und nicht aus einer zweiten,
        // ungefilterten Liste — sonst haette jede Installation eine Leiste mit
        // Tabs, die ins Leere fuehren.
        const wrapper = mount(Settings, { props: props({ sections: [other] }) });

        expect(wrapper.find('[data-brand-settings-tab="automations"]').exists()).toBe(false);
        expect(wrapper.find('[data-brand-settings-section="automations"]').exists()).toBe(false);
        expect(wrapper.find('[data-brand-settings-section="leadhub"]').exists()).toBe(true);
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

    it('survives a list value that is a map instead of a list', async () => {
        // Gemessen 22.09.2026 auf staging.adriangoldner.com:
        // `entitlements.manual.subject_types` ist als `list` deklariert, trug
        // dort aber `{'App\\Models\\User': 'Mitglied'}`. `join()` gibt es auf
        // einem Objekt nicht, der Fehler flog aus dem Setup der Seite — und
        // damit blieben die Abschnitte ALLER Addons weiss.
        const mapped = {
            ...section,
            values: { ...section.values, 'redact_keys': { authorization: 'Header', 'x-api-key': 'Schluessel' } },
        };

        const wrapper = mount(Settings, { props: props({ sections: [mapped] }) });

        expect(wrapper.find('[data-stub="Header"]').exists()).toBe(true);
        expect(wrapper.find('[data-settings-field="automations:redact_keys"] textarea').element.value)
            .toBe('authorization\nx-api-key');
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
        const wrapper = mount(Settings, { props: props({ sections: [section, other] }) });

        // Edits in two addons, only one saved. The save reloads the whole page,
        // and the reload used to refill every form. Since the sections became
        // tabs the second edit is out of sight while the first is saved, which
        // makes losing it worse rather than better: nothing on screen would
        // have shown it going.
        await wrapper.find('[data-settings-field="automations:label"] input').setValue('automations edit');

        await openTab(wrapper, 'leadhub');
        await wrapper.find('[data-settings-field="leadhub:label"] input').setValue('leadhub edit');

        await openTab(wrapper, 'automations');
        await wrapper.find('[data-settings-save="automations"]').trigger('click');

        await wrapper.setProps({ sections: [{ ...section }, { ...other }] });

        expect(wrapper.find('[data-settings-field="automations:label"] input').element.value)
            .toBe('packaged');

        await openTab(wrapper, 'leadhub');

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
