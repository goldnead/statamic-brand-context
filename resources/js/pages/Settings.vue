<script setup>
/**
 * The suite's settings screen — one page, one tab per addon that registered
 * settings.
 *
 * **Why tabs and not one page.** Twenty-two addons register, together about
 * ninety field groups, and until 22.09.2026 they stood underneath each other in
 * one scrolling page. That is not a cosmetic complaint: at ninety groups a
 * setting is not findable, and the screen's whole argument — one place to look
 * instead of twenty-two — only holds if one place also means one thing at a
 * time.
 *
 * Statamic's own `Tabs`/`TabList`/`TabTrigger`/`TabContent`, not `PublishTabs`:
 * the latter carries the blueprint semantics of a publish form, and there is no
 * blueprint here. `Tabs` is controlled through `modelValue`, which is what lets
 * the open tab come from the URL.
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
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { Head, router, toggleArchitecturalBackground } from '@statamic/cms/inertia';
import {
    Header, Button, Card, Panel, Alert, Field, Input, Textarea, Switch, Select, Badge,
    EmptyStateMenu, EmptyStateItem, Icon, Tabs, TabList, TabTrigger, TabContent,
} from '@statamic/cms/ui';
import SectionBoundary from '../components/SectionBoundary.vue';

const props = defineProps({
    // Null on an install whose migrations never ran: there is no brand row to
    // name yet, and the page still has to render what the config files say.
    brand: { type: Object, default: null },
    multiBrand: { type: Boolean, default: false },
    sections: { type: Array, required: true },
    // Which tab the page opens on, decided by the server from `?section=`.
    // Null when nothing is registered. Already checked against what this user
    // may manage, so it is never a namespace missing from `sections`.
    initialSection: { type: String, default: null },
    // Addons, die sich nicht anmelden konnten. Die Registry ueberspringt sie,
    // damit ein einzelnes fehlerhaftes Addon nicht die ganze Installation
    // mitnimmt — und deshalb muss hier stehen, dass sie fehlen.
    failures: { type: Array, default: () => [] },
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
                // Eine Liste ist nicht immer eine Liste.
                //
                // Ein Addon darf einen Wert als `list` deklarieren, dessen
                // Config auf einer Site eine Zuordnung traegt — dann kommt
                // hier ein Objekt an, `join()` gibt es darauf nicht, und der
                // Fehler nahm die GANZE Seite mit: alle Abschnitte aller
                // Addons blieben weiss, sichtbar nur in der Browserkonsole.
                // Dieselbe Falle wie beim `select` unten, gemessen am
                // 22.09.2026 an `entitlements.manual.subject_types`.
                //
                // Die Schluessel, nicht die Werte: bei einer Zuordnung
                // Typ => Beschriftung ist der Schluessel der Wert, den die
                // Liste meint.
                form[field.key] = (Array.isArray(value) ? value : Object.keys(value ?? {})).join('\n');
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

// ---------- Which tab is open ----------

const tabNames = computed(() => props.sections.map((section) => section.namespace));

/**
 * The tab to open: the one the server was asked for, or the first.
 *
 * The server has already refused a namespace this user may not manage, so
 * anything that survives to here is a tab that exists. The check is repeated
 * anyway, because `Tabs` given a `modelValue` matching no `TabContent` shows an
 * empty page under a full tab bar — a blank screen for a stale bookmark.
 */
function openable() {
    return tabNames.value.includes(props.initialSection)
        ? props.initialSection
        : (tabNames.value[0] ?? null);
}

const activeSection = ref(openable());

/** Die scrollbare Huelle um die Tableiste. */
const tabBar = ref(null);

/**
 * Den offenen Tab in den sichtbaren Ausschnitt holen.
 *
 * Ohne das ist die Scroll-Huelle schlimmer als der Ueberlauf, den sie
 * behebt: wer in der Seitenleiste auf "Webhook Manager" klickt, bekommt den
 * richtigen Inhalt, aber eine Tableiste, die ganz links steht — der aktive
 * Tab liegt ausserhalb, und die Seite sieht aus, als habe sie den ersten Tab
 * geoeffnet. Gemessen: der letzte Tab beginnt bei 2491, sichtbar ist bis
 * 1732.
 *
 * `block: 'nearest'`, damit das Holen die Seite nicht vertikal verschiebt —
 * die Tableiste steht oben, und ein Sprung dorthin waere beim Tabwechsel ein
 * Ruck ohne Anlass.
 */
function tabInSicht(namespace) {
    const shell = tabBar.value;

    if (! namespace || ! shell) return;

    const trigger = [...shell.querySelectorAll('[data-brand-settings-tab]')]
        .find((el) => el.getAttribute('data-brand-settings-tab') === namespace);

    if (typeof trigger?.scrollIntoView === 'function') {
        trigger.scrollIntoView({ block: 'nearest', inline: 'center' });
    }
}

const canScrollTabsLeft = ref(false);
const canScrollTabsRight = ref(false);

/**
 * Liest die Scrollposition der Huelle aus — siehe die Fade-Divs im Template.
 * Drei Feldzugriffe und zwei Vergleiche, billig genug fuer jedes `scroll`-
 * Ereignis. 1px Toleranz faengt Rundungsfehler an der Kante ab, die sonst im
 * Ruhezustand einen Fade flackern liessen.
 */
function updateTabFade() {
    const el = tabBar.value;
    if (! el) return;
    canScrollTabsLeft.value = el.scrollLeft > 1;
    canScrollTabsRight.value = el.scrollLeft + el.clientWidth < el.scrollWidth - 1;
}

onMounted(() => {
    tabInSicht(activeSection.value);
    nextTick(updateTabFade);
    window.addEventListener('resize', updateTabFade);
});

onBeforeUnmount(() => window.removeEventListener('resize', updateTabFade));

watch(activeSection, (namespace) => nextTick(() => tabInSicht(namespace)));

// Ein Brand-Wechsel oder eine andere Anzahl Abschnitte aendert, wie weit die
// Leiste ueberhaupt laeuft.
watch(() => props.sections, () => nextTick(updateTabFade));

/**
 * An Inertia visit re-renders this page with new props — a brand switch, and
 * the redirect after every save. If the open tab is gone from the new props
 * (fewer sections, another brand), fall back rather than leave the page blank.
 */
watch(() => props.sections, () => {
    if (! tabNames.value.includes(activeSection.value)) {
        activeSection.value = openable();
    }
});

/**
 * The second sidebar click.
 *
 * An operator already on this screen who clicks another addon's entry makes an
 * Inertia visit to the same page component with a different `?section=`: new
 * props, same instance, and nothing in the DOM changes on its own. Without
 * this the entry would do exactly what `statamic-automations` removed its own
 * settings child entry for — look like a link and go nowhere.
 *
 * Only on a change of the prop, so it never fights a tab the operator picked
 * themselves: saving comes back with the section the address bar already
 * carries, and a brand switch keeps it.
 */
watch(() => props.initialSection, (namespace) => {
    if (namespace && tabNames.value.includes(namespace)) {
        activeSection.value = namespace;
    }
});

/**
 * Keep `?section=` on the address bar in step with the open tab.
 *
 * Not decoration, and not an Inertia visit either — `history.replaceState`,
 * which changes no page state and fires no request. Two things depend on it:
 * a reload or a bookmark comes back to the tab that was open, and **saving
 * stays where it was**. Saving redirects back to the referrer, so without this
 * an operator who opened `payments` from the sidebar, switched to `invoices`
 * and saved would be dropped back on `payments` with the save apparently
 * undone.
 *
 * `history.state` is handed back untouched: Inertia keeps its serialised page
 * in there, and replacing it with null breaks the back button.
 */
watch(activeSection, (namespace) => {
    if (! namespace || typeof window === 'undefined' || ! window.history?.replaceState) return;

    try {
        const url = new URL(window.location.href);

        if (url.searchParams.get('section') === namespace) return;

        url.searchParams.set('section', namespace);
        window.history.replaceState(window.history.state, '', url);
    } catch {
        // A URL the browser will not parse is not worth a broken settings
        // screen. The tab is open either way; only the address bar lags.
    }
});

const hasSections = computed(() => props.sections.length > 0);

/**
 * A tab bar of one is a label repeating the heading under it. The `Tabs`
 * wrapper still stands, so the markup is the same shape however many addons
 * are installed.
 */
const showsTabBar = computed(() => props.sections.length > 1);

/**
 * Der Leerzustand gilt nur, wenn wirklich nichts da ist.
 *
 * Sind alle Abschnitte ausgefallen, ist `sections` ebenfalls leer — und
 * "keines der installierten Addons meldet Einstellungen an" waere dann die
 * falsche Auskunft: sie melden an, sie kommen nur nicht durch. In dem Fall
 * traegt die Seite ihre Ueberschrift und die Ausfallmeldung.
 */
const showsEmptyState = computed(() => ! hasSections.value && props.failures.length === 0);

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
watch(showsEmptyState, (empty) => toggleArchitecturalBackground(empty), { immediate: true });
</script>

<template>
    <Head :title="t('nav_settings')" />

    <div class="max-w-page mx-auto" data-max-width-wrapper>
        <!-- An empty state gets a centered heading rather than <Header>, the
             way core does it (pages/forms/Index.vue:28-33). <Header> carries a
             toolbar and a page-action slot, and a toolbar over nothing is what
             makes an empty screen look like a broken one. -->
        <template v-if="showsEmptyState">
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

        <!-- Ein Addon hat sich nicht anmelden koennen. Die Registry hat es
             uebersprungen statt zu werfen, damit nicht ein einzelnes Addon das
             ganze Control Panel mitnimmt. Der Preis dafuer ist, dass der
             Ausfall sonst niemandem auffiele: sein Abschnitt fehlt einfach.
             Deshalb steht er hier, mit Namen und Grund. -->
        <Alert v-if="failures.length" variant="error" class="mb-6" data-brand-settings-failures>
            <p>{{ t('settings_failed_heading') }}</p>
            <ul class="mt-2 list-inside list-disc space-y-0.5">
                <li v-for="failure in failures" :key="failure.addon">
                    <span class="font-mono text-sm">{{ failure.addon }}</span> — {{ failure.reason }}
                </li>
            </ul>
        </Alert>

        <!-- Ein Tab je Addon. Bei zweiundzwanzig Addons und rund neunzig
             Feldgruppen ist die eine lange Seite, die das hier bis zum
             22.09.2026 war, kein Schoenheitsfehler: eine Einstellung ist darin
             nicht zu finden. Welcher Tab aufgeht, entscheidet der Server aus
             `?section=` — daran haengen die Seitenleisten-Eintraege je Addon. -->
        <Tabs v-model="activeSection">
            <!-- Statamics `TabList` ist eine reine Flex-Reihe: kein
                 `overflow-x`, kein `flex-wrap`. Gemessen am 22.09.2026 im
                 laufenden Playground bei 1920px Fenster: 22 Tabs brauchen
                 2187px, die Spalte ist 1360px breit, und der letzte Tab
                 ("Webhook Manager") endete bei 2559 gegen ein Listenende bei
                 1732. Rund ein Viertel der Addons war nicht anklickbar.

                 **Gescrollt, nicht umgebrochen, und das ist gemessen.** Mit
                 `flex-wrap: wrap` entstehen bei diesen Namen nicht zwei,
                 sondern DREI Zeilen — und Statamics `TabsIndicator` ist
                 absolut zur Liste positioniert: er folgt der Spalte des
                 aktiven Tabs, nicht seiner Zeile. Im Bild stand der
                 Unterstrich unter "Statamic ToC" in Zeile 3, waehrend
                 "Activity" in Zeile 1 der aktive Tab war. Das zu reparieren
                 hiesse, den Aktivzustand einer Core-Komponente selbst
                 nachzubauen, und der naechste Statamic-Sprung haette ihn
                 wieder.

                 Der Einwand gegen Scrollen ist richtig — man sieht nicht,
                 dass es mehr gibt. Hier faengt ihn die zweite Haelfte dieses
                 Tickets auf: jedes Addon hat seinen eigenen Eintrag in der
                 Seitenleiste, die Leiste ist also nicht der einzige Weg zu
                 einem Addon. Gleiche Huelle wie in `statamic-flow-canvas`
                 (61f7701), damit die Familie eine Antwort auf ein Problem hat
                 und nicht zwei. -->
            <div v-if="showsTabBar" class="brand-settings-tab-fade-wrap">
                <div
                    ref="tabBar"
                    class="-mx-1 overflow-x-auto px-1"
                    data-brand-settings-tabs-shell
                    @scroll="updateTabFade"
                >
                    <TabList class="flex-nowrap" data-brand-settings-tabs>
                        <TabTrigger
                            v-for="section in sections"
                            :key="section.namespace"
                            :name="section.namespace"
                            :text="section.title"
                            :data-brand-settings-tab="section.namespace"
                        />
                    </TabList>
                </div>

                <!--
                    Weiche Kante statt Dekoration: der Fade zeigt nur, wenn in diese
                    Richtung wirklich noch Tabs liegen, und verschwindet, sobald das Ende
                    erreicht ist — sonst waere er eine Kante, die am Ende der Liste luegt.
                    Aria-hidden und `pointer-events: none`, damit er keinen Klick schluckt:
                    der Tab darunter bleibt der Treffer, nicht das Overlay. Farbe kommt aus
                    dem Statamic-Token `var(--theme-color-content-bg)` (derselbe Wert, den
                    `bg-content-bg` aufloest), kein fester Hex-Wert, das traegt Hell und
                    Dunkel gleichermassen. Plain CSS im <style>-Block unten statt
                    Tailwind-Utility-Klassen: dieses Addon hat keinen eigenen Tailwind-Build
                    (kein `@import "tailwindcss"`, kein Plugin in vite.config.js) und traegt
                    nur, was Statamics eigenes CP-Bundle zufaellig schon mitbringt — eine neue
                    Utility-Klasse wie `from-content-bg` waere lautlos leer geblieben. Baugleich
                    mit der Gegenstelle in NodeLibrary.vue (statamic-flow-canvas) und
                    Settings.vue (statamic-brand-context) — zweimal gebaut statt geteilt,
                    weil brand-context nicht von flow-canvas abhaengt (siehe composer.json)
                    und eine neue Abhaengigkeit fuer eine Fade-Kante zu teuer waere.
                    Aenderung hier: die Gegenstelle im jeweils anderen Repo nachziehen.
                -->
                <div
                    v-show="canScrollTabsLeft"
                    aria-hidden="true"
                    data-brand-settings-tabs-fade="left"
                    class="brand-settings-tab-fade brand-settings-tab-fade--left"
                />
                <div
                    v-show="canScrollTabsRight"
                    aria-hidden="true"
                    data-brand-settings-tabs-fade="right"
                    class="brand-settings-tab-fade brand-settings-tab-fade--right"
                />
            </div>

            <!-- Jeder Abschnitt in seiner eigenen Grenze: die Feldlisten kommen
                 aus fremden Addons, und ein Render-Fehler in einem darf nicht
                 die Seite aller nehmen. Siehe SectionBoundary. -->
            <TabContent
                v-for="section in sections"
                :key="section.namespace"
                :name="section.namespace"
            >
            <SectionBoundary
                :namespace="section.namespace"
                :title="section.title"
            >
            <section class="mt-6" :data-brand-settings-section="section.namespace">
                <div class="mb-3 flex items-center justify-between gap-4">
                    <div>
                        <!-- Nur ohne Tableiste. Steht der Addon-Name schon im
                             offenen Tab direkt darueber, ist die Ueberschrift
                             dieselbe Beschriftung ein zweites Mal, zwei Zeilen
                             tiefer. Auf einer Installation mit einem einzigen
                             Addon gibt es keine Leiste — dann stuende der Name
                             des Addons sonst nirgends auf der Seite. Die
                             Config-Zeile und der Speichern-Knopf bleiben in
                             beiden Faellen, sie sind der Grund fuer diese
                             Reihe. -->
                        <h2
                            v-if="! showsTabBar"
                            class="text-lg font-medium text-gray-900 dark:text-gray-100"
                        >{{ section.title }}</h2>
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
                                         (ui-vocabulary, antipattern table).

                                         Der Platzhalter heisst `Choose...` mit
                                         drei Punkten, nicht mit dem
                                         Auslassungszeichen: Statamic uebersetzt
                                         genau diese Zeichenkette
                                         (`lang/de.json`: "Choose..." =>
                                         "Auswählen…"). Die Fassung mit `…`
                                         trifft keinen Eintrag und stand deshalb
                                         englisch auf einer deutschen Seite. -->
                                    <Select
                                        v-else-if="field.type === 'select'"
                                        :model-value="state[section.namespace].form[field.key] || null"
                                        :options="field.options"
                                        :placeholder="__('Choose...')"
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
            </TabContent>
        </Tabs>
        </template>
    </div>
</template>

<style scoped>
/* Plain CSS, not Tailwind utilities — see the template comment above the fade
   divs for why. `var(--theme-color-content-bg)` is the same custom property
   `bg-content-bg` resolves to, so this tracks the CP's light/dark theme (and
   any custom accent) without a second definition of what that colour is. */
.brand-settings-tab-fade-wrap {
    position: relative;
}
.brand-settings-tab-fade {
    position: absolute;
    top: 0;
    bottom: 0;
    width: 1.5rem;
    z-index: 1;
    pointer-events: none;
}
.brand-settings-tab-fade--left {
    left: 0;
    background: linear-gradient(to right, var(--theme-color-content-bg), transparent);
}
.brand-settings-tab-fade--right {
    right: 0;
    background: linear-gradient(to left, var(--theme-color-content-bg), transparent);
}
</style>
