<script setup>
/**
 * Ein Abschnitt, der die anderen nicht mitreisst.
 *
 * Die Einstellungs-Seite zeigt EINEN Abschnitt je Addon, und sie kommen aus
 * fremdem Code: jedes Addon liefert seine Feldliste selbst. Ohne diese Grenze
 * gilt Vues Normalfall — ein Render-Fehler in einem Feld reisst den ganzen Baum
 * ab, und der Betreiber sieht eine leere Seite statt neunzehn Abschnitten, von
 * denen achtzehn in Ordnung sind.
 *
 * Das ist kein gedachter Fall. Am 07.09.2026 hat genau das die Seite lahmgelegt:
 * `statamic-invoices` bot `tax.prices_include_tax` als `select` an, in der
 * Config stand dort ein `true`, die Select-Komponente kam mit einem `bool` nicht
 * klar — und LeadHub, Automations, Payments und Webhook Manager waren mit weg.
 *
 * Die Ursache ist behoben (Auswahlwerte werden als Zeichenkette in die Form
 * gelegt). Diese Grenze steht trotzdem, denn die Ursache war nicht die letzte
 * ihrer Art: das Ticket sieht neunzehn weitere Addons vor, und jedes bringt
 * eine eigene Feldliste mit.
 *
 * Was sie NICHT tut: den Fehler verschlucken. Er geht in die Konsole, und an
 * der Stelle des Abschnitts steht, welches Addon es war — sonst wuesste
 * niemand, wo zu suchen ist.
 *
 * ## Wie weit sie reicht, und wo sie aufhoert
 *
 * `onErrorCaptured` faengt Fehler aus **Kind-Komponenten**. Der beobachtete
 * Absturz war genau das: `Select` bekam einen `bool` und warf beim Rendern.
 * Solche Faelle — und das sind die, die aus fremden Feldlisten kommen — sind
 * abgedeckt.
 *
 * **Nicht abgedeckt ist ein Fehler in `Settings.vue`s eigenem Render.**
 * Slot-Inhalt wird im Scope des Eltern kompiliert; wirft er, ist es der Fehler
 * des Eltern, und kein Kind kann ihn abfangen. Nachgemessen am 07.09.2026 mit
 * einem absichtlich hineingelegten Wurf: die Seite blieb leer, die Grenze griff
 * nicht.
 *
 * Wer auch das abfangen will, muss den Abschnittsrumpf zu einer eigenen
 * Komponente machen (`<SettingsSection :section="…">`), damit sein Render ein
 * Kind-Render ist. Das ist ein groesserer Umbau und hier bewusst nicht gemacht:
 * die Fehler, die aus den neunzehn kommenden Addons zu erwarten sind, stecken
 * in den Feldern, nicht in dieser Seite.
 */
import { onErrorCaptured, ref } from 'vue';

const props = defineProps({
    namespace: { type: String, required: true },
    title: { type: String, default: '' },
});

const kaputt = ref(false);

onErrorCaptured((fehler) => {
    kaputt.value = true;

    // Laut, damit der Fehler auffindbar bleibt. Ein stiller Rueckfall waere
    // schlimmer als die leere Seite: die sah man wenigstens.
    console.error(`[brand-context] Der Einstellungs-Abschnitt "${props.namespace}" konnte nicht dargestellt werden.`, fehler);

    // `false` stoppt die Weitergabe — genau dafuer steht die Grenze hier.
    return false;
});
</script>

<template>
    <div
        v-if="kaputt"
        :data-settings-section-broken="namespace"
        class="rounded-md border border-red-300 bg-red-50 p-4 dark:border-red-800 dark:bg-red-950"
    >
        <p class="font-medium text-red-800 dark:text-red-200">{{ title || namespace }}</p>
        <p class="mt-1 text-sm text-red-700 dark:text-red-300">
            {{ __('This addon could not render its settings. The other sections on this page are unaffected; the details are in the browser console.') }}
        </p>
    </div>
    <slot v-else />
</template>
