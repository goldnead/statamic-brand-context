<script setup>
/**
 * The suite's settings screen — one page, one section per addon that
 * registered settings.
 *
 * Every control is generated from `sections[].groups`, which the server built
 * from each addon's own `settingsGroups()`: the same definition the validation
 * and the config override read. The read-only screens this family used to ship
 * kept their own list of labels in JavaScript, so the screen was a second
 * description of the config file that could disagree with it and never say so.
 *
 * **Each section saves on its own.** The server takes one namespace per
 * request, so a validation failure in one addon cannot block saving another,
 * and a slow save in one does not hold the rest of the page.
 *
 * Saving answers with the settings as they now stand, which is not always what
 * was typed: an empty nullable field comes back null, a number typed into a
 * text box comes back a number, and a value set back to the packaged default
 * comes back as the default with the stored override deleted. The form takes
 * the answer, so the screen and the installation cannot drift.
 */
import { computed, reactive, watch } from 'vue';
import { Head, router, toggleArchitecturalBackground } from '@statamic/cms/inertia';
import {
    Header, Button, Card, Panel, Alert, Field, Input, Textarea, Switch, Select, Badge,
    EmptyStateMenu, EmptyStateItem, Icon,
} from '@statamic/cms/ui';
import SectionBoundary from '../components/SectionBoundary.vue';

const props = defineProps({
    // Null on an install whose migrations never ran: there is no brand row to
    // name yet, and the page still has to render what the config files say.
    brand: { type: Object, default: null },
    multiBrand: { type: Boolean, default: false },
    sections: { type: Array, required: true },
    updateUrl: { type: String, required: true },
    addonsUrl: { type: String, required: true },
    writable: { type: Boolean, default: true },
});

const t = (key, replacements = {}) => __(`brand-context::messages.${key}`, replacements);

// ---------- Form state, one entry per section ----------

/**
 * A `list` is edited as one textarea of lines, because that is how a set of key
 * names is read and pasted. The conversion lives here rather than on the server
 * so the server keeps receiving an array and nothing has to guess a separator.
 */
function toForm(section, values) {
    const form = {};

    for (const group of section.groups) {
        for (const field of group.fields) {
            const value = values[field.key];

            if (field.type === 'list') {
                form[field.key] = (value ?? []).join('\n');
            } else if (field.type === 'boolean') {
                form[field.key] = value ?? false;
            } else if (field.type === 'select') {
                // Eine Auswahl traegt IMMER eine Zeichenkette.
                //
                // Die Optionswerte sind Zeichenketten (`normaliseOptions`), und
                // der Server castet `select` auf der Gegenseite ebenfalls zu
                // einer. Die Form tat es nicht — und ein Addon, dessen Config
                // an der Stelle ein `true` stehen hatte, reichte einen `bool`
                // an die Select-Komponente durch. Die kam damit nicht klar, der
                // Render-Fehler nahm die GANZE Seite mit, und alle Abschnitte
                // aller Addons standen leer.
                //
                // Gefunden 07.09.2026 beim Bau der Einstellungsseite von
                // `statamic-invoices` (`tax.prices_include_tax`).
                form[field.key] = value === null || value === undefined ? '' : String(value);
            } else {
                form[field.key] = value ?? '';
            }
        }
    }

    return form;
}

function fromForm(section, form) {
    const out = {};

    for (const group of section.groups) {
        for (const field of group.fields) {
            const value = form[field.key];
            out[field.key] = field.type === 'list'
                ? String(value ?? '').split('\n').map((line) => line.trim()).filter(Boolean)
                : value;
        }
    }

    return out;
}

const state = reactive({});

function fill(section) {
    const form = toForm(section, section.values);

    state[section.namespace] = {
        form,
        saved: JSON.stringify(form),
        saving: false,
        errors: {},
    };
}

function reset() {
    props.sections.forEach(fill);
}

reset();

/**
 * The brand the forms on screen belong to. A brand switch has to wipe every
 * form; a save must not.
 */
let shownBrand = props.brand?.id ?? null;

/** The namespace whose save is in flight, if any. */
let saving = null;

/**
 * An Inertia visit re-renders this page with new props on the same component
 * instance: a brand switch, a back button, and the redirect after every save.
 *
 * Refilling everything on all three was wrong. Saving one section reloads the
 * whole page, so an operator with two sections open who saved one lost the
 * unsaved edits in the other — silently, with no warning and no error, the
 * form simply showing the old server values again. Now only the section that
 * was actually saved is refilled, plus any section nobody has touched.
 *
 * A brand switch is the exception and still wipes the lot: keeping one brand's
 * typed values on screen under another brand's name is the one thing this
 * screen must never do.
 */
watch(() => props.sections, () => {
    const brandChanged = (props.brand?.id ?? null) !== shownBrand;
    shownBrand = props.brand?.id ?? null;

    for (const section of props.sections) {
        const entry = state[section.namespace];

        if (brandChanged || ! entry || section.namespace === saving || ! dirty(section)) {
            fill(section);
        }
    }

    saving = null;
});

const dirty = (section) => {
    const entry = state[section.namespace];

    return entry ? JSON.stringify(entry.form) !== entry.saved : false;
};

/**
 * Errors that belong to no control in this section. There should be none —
 * every validated key has a field here — but a rule added on the server and
 * not here would otherwise be rejected silently.
 */
function generalErrors(section) {
    const known = new Set(section.groups.flatMap((g) => g.fields.map((f) => `settings.${f.key}`)));

    return Object.entries(state[section.namespace]?.errors ?? {})
        .filter(([key]) => ! known.has(key))
        .map(([, messages]) => (Array.isArray(messages) ? messages[0] : messages));
}

function errorFor(section, field) {
    const messages = state[section.namespace]?.errors?.[`settings.${field.key}`];

    return Array.isArray(messages) ? messages[0] : messages;
}

/**
 * Save one section through Inertia.
 *
 * `router.patch` rather than axios: it drives the Control Panel's progress
 * bar, the flash toast the controller sets, the dirty-state guard and the back
 * button. The controller redirects back, so the visit re-renders this page and
 * `watch(props.sections)` refills every form from what the server now holds —
 * which is why nothing here reads a response body.
 */
function save(section) {
    const entry = state[section.namespace];

    if (! entry || entry.saving) return;

    entry.saving = true;
    entry.errors = {};
    saving = section.namespace;

    router.patch(props.updateUrl, {
        namespace: section.namespace,
        settings: fromForm(section, entry.form),
    }, {
        preserveScroll: true,
        // Not preserveState: the point of the round trip is to take the
        // server's version of the values, and keeping local state would leave
        // the form showing what was typed rather than what was stored.
        // A validation failure keeps the page (Inertia preserves state when
        // the response carries errors), so the watch above never runs and the
        // in-flight marker has to be cleared here instead.
        onError: (errors) => { entry.errors = errors || {}; saving = null; },
        onFinish: () => { entry.saving = false; },
    });
}

const hasSections = computed(() => props.sections.length > 0);

/**
 * Core puts the architectural-lines treatment behind an empty state, and only
 * there — a full page of forms on top of it reads as decoration nobody chose.
 *
 * `toggleArchitecturalBackground(enable)`, not `useArchitecturalBackground()`:
 * the latter takes no arguments at all
 * (`dist-package/types/pages/layout/architectural-background.d.ts`). Passing it
 * a getter, as the first version here did, was silently ignored and left the
 * lines switched on behind every section. Guessing a signature is exactly what
 * the studio's UI standard says not to do, and this is what it costs.
 */
watch(hasSections, (any) => toggleArchitecturalBackground(! any), { immediate: true });
</script>

<template>
    <Head :title="t('nav_settings')" />

    <div class="max-w-page mx-auto" data-max-width-wrapper>
        <!-- An empty state gets a centered heading rather than <Header>, the
             way core does it (pages/forms/Index.vue:28-33). <Header> carries a
             toolbar and a page-action slot, and a toolbar over nothing is what
             makes an empty screen look like a broken one. -->
        <template v-if="! hasSections">
            <header class="py-8 pt-16 text-center">
                <h1 class="flex items-center justify-center gap-2 text-[25px] font-medium antialiased sm:gap-3">
                    <Icon name="sliders-horizontal" class="size-5 text-gray-500" />{{ t('nav_settings') }}
                </h1>
            </header>

            <EmptyStateMenu :heading="t('settings_empty_heading')" data-brand-settings-empty>
                <!-- One real way forward, not a dead end: settings appear here
                     when an addon that offers them is installed, and the addon
                     list is where you see what is. -->
                <EmptyStateItem
                    :href="addonsUrl"
                    icon="addons"
                    :heading="t('settings_empty_item_heading')"
                    :description="t('settings_empty_description')"
                />
            </EmptyStateMenu>
        </template>

        <template v-else>
        <Header :title="t('nav_settings')" icon="sliders-horizontal">
            <Badge v-if="multiBrand" :text="brand?.name" color="blue" data-brand-settings-brand />
        </Header>

        <!-- Which brand these values belong to is the one thing this screen
             must never leave ambiguous. In single-brand mode there is nothing
             to say; in multi-brand the switcher decides, and saying so here is
             cheaper than a support ticket about settings that "reset
             themselves" after switching. -->
        <Alert v-if="multiBrand" variant="default" class="mb-6">
            {{ t('settings_brand_notice', { brand: brand?.name }) }}
        </Alert>

        <!-- The table is missing, which is what an upgrade without migrations
             looks like. Reading still works — everything below shows the
             packaged values — so the page stays useful and says what to run
             rather than handing over an SQL error on the first Save. -->
        <Alert v-if="! writable" variant="warning" class="mb-6" data-brand-settings-readonly>
            {{ t('settings_not_writable') }}
        </Alert>

        <div class="space-y-8">
            <!-- Jeder Abschnitt in seiner eigenen Grenze: die Feldlisten kommen
                 aus fremden Addons, und ein Render-Fehler in einem darf nicht
                 die Seite aller nehmen. Siehe SectionBoundary. -->
            <SectionBoundary
                v-for="section in sections"
                :key="section.namespace"
                :namespace="section.namespace"
                :title="section.title"
            >
            <section :data-brand-settings-section="section.namespace">
                <div class="mb-3 flex items-center justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ section.title }}</h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ t('settings_follows_config', { path: section.config_path }) }}
                        </p>
                    </div>

                    <Button
                        v-if="writable"
                        variant="primary"
                        :text="state[section.namespace]?.saving ? __('Saving…') : __('Save')"
                        :disabled="state[section.namespace]?.saving || ! dirty(section)"
                        :data-settings-save="section.namespace"
                        @click="save(section)"
                    />
                </div>

                <Alert
                    v-if="generalErrors(section).length"
                    variant="error"
                    class="mb-4"
                    :data-settings-form-errors="section.namespace"
                >
                    <ul class="list-disc list-inside space-y-0.5">
                        <li v-for="(message, i) in generalErrors(section)" :key="i">{{ message }}</li>
                    </ul>
                </Alert>

                <div class="space-y-6">
                    <Panel
                        v-for="group in section.groups"
                        :key="group.title"
                        :heading="group.title"
                    >
                        <Card>
                            <p v-if="group.description" class="mb-4 text-sm text-gray-500 dark:text-gray-400">
                                {{ group.description }}
                            </p>

                            <!-- The marker sits on a wrapper, not on `Field`:
                                 Field renders its own root and does not pass
                                 stray attributes through, so the hook a test
                                 reaches for would not exist in the DOM. -->
                            <div
                                v-for="field in group.fields"
                                :key="field.key"
                                class="mb-5 last:mb-0"
                                :data-settings-field="`${section.namespace}:${field.key}`"
                            >
                                <Field :label="field.label" :instructions="field.description">
                                    <Switch
                                        v-if="field.type === 'boolean'"
                                        :model-value="state[section.namespace].form[field.key]"
                                        @update:model-value="state[section.namespace].form[field.key] = $event"
                                    />
                                    <!-- `options` arrives as {value: label};
                                         Select wants a list of objects. Bound
                                         to null rather than '' when unset: an
                                         empty string counts as a selection and
                                         renders a blank trigger with a clear
                                         button offering to clear nothing
                                         (ui-vocabulary, antipattern table). -->
                                    <Select
                                        v-else-if="field.type === 'select'"
                                        :model-value="state[section.namespace].form[field.key] || null"
                                        :options="field.options"
                                        :placeholder="__('Choose…')"
                                        adaptive-width
                                        @update:model-value="state[section.namespace].form[field.key] = $event"
                                    />
                                    <Textarea
                                        v-else-if="field.type === 'list'"
                                        :model-value="state[section.namespace].form[field.key]"
                                        :rows="8"
                                        class="font-mono text-sm"
                                        @update:model-value="state[section.namespace].form[field.key] = $event"
                                    />
                                    <!--
                                        Mehrzeiliger Fliesstext, nicht Monospace und keine
                                        acht Zeilen wie bei `list`: hier steht Prosa, kein
                                        Datenblock. Der Fall, fuer den es ihn gibt, ist die
                                        Widerrufsbelehrung in `offers` — die traegt keine
                                        255 Zeichen und gehoert trotzdem dem Betreiber,
                                        nicht der `.env`.
                                    -->
                                    <Textarea
                                        v-else-if="field.type === 'text'"
                                        :model-value="state[section.namespace].form[field.key]"
                                        :rows="6"
                                        :placeholder="field.nullable ? __('Default') : ''"
                                        @update:model-value="state[section.namespace].form[field.key] = $event"
                                    />
                                    <Input
                                        v-else
                                        :model-value="state[section.namespace].form[field.key]"
                                        :type="field.type === 'integer' ? 'number' : 'text'"
                                        :input-attrs="field.min !== undefined ? { min: field.min } : {}"
                                        :placeholder="field.nullable ? __('Default') : ''"
                                        @update:model-value="state[section.namespace].form[field.key] = $event"
                                    />

                                    <p
                                        v-if="errorFor(section, field)"
                                        class="mt-1 text-sm text-red-600 dark:text-red-400"
                                        :data-settings-field-error="`${section.namespace}:${field.key}`"
                                    >{{ errorFor(section, field) }}</p>
                                </Field>
                            </div>
                        </Card>
                    </Panel>
                </div>
            </section>
            </SectionBoundary>
        </div>
        </template>
    </div>
</template>
