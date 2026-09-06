<?php

namespace Goldnead\BrandContext\Support;

use Goldnead\BrandContext\Models\Brand;

/**
 * Wie eine Marke aussieht — an einer Stelle, nicht in drei Blade-Dateien.
 *
 * ## Warum es das gibt
 *
 * Adrian ist am 05.09.2026 einen Testkauf durchgegangen. Befund: die drei
 * Dinge, die der Kaeufer nach dem Kauf tatsaechlich in der Hand haelt —
 * Bestaetigungsmail, Rechnungsmail, Rechnung —, sehen nach nichts aus. Die
 * Verkaufsseite ist gestaltet, alles danach ist Rohzustand.
 *
 * Die naheliegende Reparatur waere gewesen, die Farben aus
 * `suite-shop/public/landing/landing.css` dreimal abzuschreiben. Dann hat man
 * drei Quellen, die auseinanderlaufen, und beim naechsten Markenwechsel sucht
 * jemand die dritte. Also gibt es eine.
 *
 * ## Wo die Werte liegen
 *
 * In `Brand::$settings['identity']` — dem JSON-Feld, das das Marken-Modell
 * ohnehin hat. **Keine neue Tabelle und keine neue Migration:** eine Marke
 * traegt ihre Erscheinung schon, es fehlte nur ein Name dafuer. Was dort nicht
 * steht, kommt aus `config('brand-context.identity')`; was auch dort fehlt, aus
 * den Vorgaben hier. Eine Installation ohne jede Einstellung bekommt damit ein
 * lesbares Dokument statt einer leeren Stelle.
 *
 * ## Die Regeln, die diese Klasse durchsetzt
 *
 * Sie stehen im Ticket und sind keine Geschmacksfrage:
 *
 * - **Das Logo ist ein Dateipfad, nie eine URL.** Mail-Clients blockieren
 *   entfernte Bilder standardmaessig — ein Logo per `https://` ist beim ersten
 *   Oeffnen ein leerer Kasten. Und eine Rechnung ist ein Zehn-Jahre-Dokument:
 *   sie darf beim Rendern nichts nachladen, was es in zehn Jahren vielleicht
 *   nicht mehr gibt. {@see logoUrlLooking()} weist eine URL darum ab, statt sie
 *   durchzureichen.
 * - **Keine Webfonts.** Bricolage Grotesque und Hanken Grotesk gibt es im
 *   Postfach nicht. {@see fontStack()} liefert eine System-Schriftliste.
 * - **Farbe ueberall, aber als Wert.** Wer sie einsetzt, schreibt sie inline;
 *   ein Stylesheet-Link waere wieder ein externer Abruf.
 *
 * ## Hell, nicht dunkel
 *
 * Der Grund der Verkaufsseite ist `--ink #0a0f1e`. Das traegt am Bildschirm.
 * **Eine Rechnung wird gedruckt**, und eine ganzflaechige Toenung kostet dort
 * Toner und Lesbarkeit. Deshalb ist {@see paper()} der Grund und {@see ink()}
 * die Schrift, nicht umgekehrt — die Marke traegt ueber Logo, Linien und
 * {@see accent()}.
 */
class BrandIdentity
{
    /**
     * Die Vorgaben, wenn nichts eingestellt ist.
     *
     * Die Werte stammen aus `suite-shop/public/landing/landing.css`, `:root` —
     * der einzigen gebauten Fassung der Marke adriangoldner.dev, Stand
     * 06.09.2026. Sie stehen hier, damit niemand sie ein viertes Mal
     * abschreiben muss; wer sie aendert, aendert sie fuer Mail und Rechnung
     * gleichzeitig.
     */
    public const DEFAULTS = [
        'name' => 'adriangoldner.dev',
        'ink' => '#0a0f1e',
        'accent' => '#0f1629',
        'paper' => '#eef2f8',
        // Nicht `--slate #98a5bb` aus der Verkaufsseite, obwohl der Rest von
        // dort kommt.
        //
        // Dort steht er auf dunklem Grund und ist hell genug. Mail und Rechnung
        // stehen auf WEISSEM Grund, und dort erreicht `#98a5bb` nur 2,5:1 —
        // unter den 4,5:1, die lesbarer Fliesstext braucht. Ein Nebentext, den
        // man nicht lesen kann, ist kein Nebentext, sondern ein Fleck.
        //
        // `#5b6880` ist derselbe Blauton eine Stufe tiefer und kommt auf 5,6:1.
        // Gerechnet, nicht geschaetzt.
        'muted' => '#5b6880',
        'logo' => null,
    ];

    /** @param array<string, mixed> $werte */
    private function __construct(private readonly array $werte) {}

    /**
     * Die Erscheinung einer Marke — oder die der Standardmarke.
     *
     * Ein `null` ist kein Fehler: ein Versand aus einem Hintergrundlauf hat
     * keine Marke im Kontext, und eine Rechnung darf daran nicht scheitern.
     * Dann gilt die Standardmarke, und wenn es auch die nicht gibt, die
     * Vorgaben.
     */
    public static function for(?Brand $brand = null): self
    {
        $brand ??= Brand::default();

        $ausDerMarke = [];

        if ($brand instanceof Brand) {
            $settings = $brand->settings;
            $kandidat = is_array($settings) ? ($settings['identity'] ?? null) : null;
            $ausDerMarke = is_array($kandidat) ? $kandidat : [];
        }

        $ausDerConfig = config('brand-context.identity');
        $ausDerConfig = is_array($ausDerConfig) ? $ausDerConfig : [];

        // Reihenfolge: was an der Marke steht, schlaegt die Config, schlaegt die
        // Vorgabe. Leere Werte zaehlen dabei nicht als Antwort — ein `''` in
        // einer Einstellung ist keine Farbe, sondern eine vergessene Zeile.
        $werte = self::DEFAULTS;

        foreach ([$ausDerConfig, $ausDerMarke] as $quelle) {
            foreach ($quelle as $schluessel => $wert) {
                if (! array_key_exists($schluessel, self::DEFAULTS)) {
                    continue;
                }

                if (is_string($wert) && trim($wert) !== '') {
                    $werte[$schluessel] = trim($wert);
                }
            }
        }

        // Der Markenname als LETZTER Ausweg fuer die Wortmarke — nach der
        // Config, nicht davor.
        //
        // Das stand zuerst andersherum, und der Fehler war im Bild sofort zu
        // sehen: die Bestellbestaetigung des Ladens trug „Default" statt
        // „adriangoldner.dev". Die Marke in der Datenbank heisst so, und ein
        // ABGELEITETER Wert hatte damit eine ausdrueckliche Einstellung
        // geschlagen.
        //
        // Die Regel dahinter: was jemand hingeschrieben hat, gewinnt gegen das,
        // was wir uns hergeleitet haben — egal aus welcher Schicht die
        // Herleitung kommt. Eine ausdrueckliche `settings.identity.name` an der
        // Marke schlaegt die Config weiterhin, denn die ist hingeschrieben.
        if ($werte['name'] === self::DEFAULTS['name']
            && ! isset($ausDerConfig['name'])
            && $brand instanceof Brand
            && is_string($brand->name)
            && trim($brand->name) !== '') {
            $werte['name'] = trim($brand->name);
        }

        return new self($werte);
    }

    /** Die Wortmarke, wie sie im Kopf eines Dokuments steht. */
    public function name(): string
    {
        return (string) $this->werte['name'];
    }

    /** Die Schriftfarbe auf hellem Grund. */
    public function ink(): string
    {
        return $this->farbe('ink');
    }

    /** Die Akzentfarbe fuer Linien, Ueberschriften, den Betrag. */
    public function accent(): string
    {
        return $this->farbe('accent');
    }

    /** Der helle Grund. Auch im PDF — es wird gedruckt. */
    public function paper(): string
    {
        return $this->farbe('paper');
    }

    /** Fuer Nebensaechliches: Fusszeile, Hinweise, Kleingedrucktes. */
    public function muted(): string
    {
        return $this->farbe('muted');
    }

    /**
     * Eine Farbe, und nur wenn sie wie eine aussieht.
     *
     * Was nicht als `#rgb` oder `#rrggbb` lesbar ist, faellt auf die Vorgabe
     * zurueck. Ein Tippfehler in einer Einstellung soll eine Rechnung nicht
     * unlesbar machen — und ein ungeprueft eingesetzter Wert in einem
     * `style="…"` waere ausserdem ein Weg, fremdes CSS in eine Mail zu
     * schreiben.
     */
    private function farbe(string $schluessel): string
    {
        $wert = (string) $this->werte[$schluessel];

        return preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $wert) === 1
            ? $wert
            : (string) self::DEFAULTS[$schluessel];
    }

    /**
     * Der Pfad zum Logo auf der Platte — oder null.
     *
     * **Nie eine URL.** Siehe Klassenkommentar: ein entferntes Bild ist in der
     * Mail ein leerer Kasten und in der Rechnung ein Risiko auf zehn Jahre.
     * Wer eine eintraegt, bekommt hier `null` und damit ein Dokument ohne Logo
     * statt eines mit kaputtem Bild.
     *
     * Auch `null`, wenn die Datei nicht existiert. Ein Pfad, der ins Leere
     * zeigt, ist dasselbe wie keiner — nur lauter.
     */
    public function logoPath(): ?string
    {
        $pfad = $this->werte['logo'];

        if (! is_string($pfad) || trim($pfad) === '' || self::logoUrlLooking($pfad)) {
            return null;
        }

        $pfad = trim($pfad);

        return is_file($pfad) && is_readable($pfad) ? $pfad : null;
    }

    /** Ob etwas nach einer entfernten Adresse aussieht statt nach einem Pfad. */
    public static function logoUrlLooking(string $wert): bool
    {
        $wert = strtolower(trim($wert));

        return preg_match('#^(https?:)?//#', $wert) === 1 || str_starts_with($wert, 'data:');
    }

    /**
     * Das Logo als SVG-Text, zum direkten Einsetzen ins PDF.
     *
     * Nur SVG, und nur wenn die Datei wirklich eines ist. Ein PNG hier
     * einzubetten hiesse, es als `data:`-URI ins HTML zu schreiben — das
     * ueberleben die Druckmaschinen unterschiedlich gut, und in einer Mail
     * werfen es viele Clients heraus. Fuer die Mail ist ohnehin der CID-Anhang
     * der Weg, und der braucht {@see logoPath()}, nicht diesen Text.
     */
    public function logoSvg(): ?string
    {
        $pfad = $this->logoPath();

        if ($pfad === null || strtolower((string) pathinfo($pfad, PATHINFO_EXTENSION)) !== 'svg') {
            return null;
        }

        $inhalt = @file_get_contents($pfad);

        if (! is_string($inhalt) || ! str_contains($inhalt, '<svg')) {
            return null;
        }

        // Was vor dem `<svg` steht — XML-Deklaration, DOCTYPE, Kommentare —
        // gehoert nicht mitten in ein HTML-Dokument.
        $ab = strpos($inhalt, '<svg');

        return $ab === false ? null : substr($inhalt, $ab);
    }

    /**
     * Die Schriftliste fuer Mail und Rechnung.
     *
     * **Kein Webfont.** Die Hausschriften gibt es im Postfach nicht, und ein
     * `@font-face` mit entfernter Quelle waere derselbe externe Abruf, den das
     * Logo hier nicht machen darf. `DejaVu Sans` steht mit drin, weil die
     * PHP-Druckmaschinen sie mitbringen und ohne sie Umlaute verlieren.
     */
    public function fontStack(): string
    {
        // **Ohne Anfuehrungszeichen, und das ist kein Schoenheitsfehler.**
        //
        // Der Wert wird in Blade mit `{{ }}` ausgegeben, also HTML-maskiert.
        // In einem `style="…"`-Attribut ist das harmlos: der HTML-Parser macht
        // aus `&quot;` wieder ein `"`, bevor CSS es sieht. In einem
        // `<style>`-BLOCK passiert das nicht — dort steht dann woertlich
        // `&quot;Segoe UI&quot;`, CSS haelt die ganze Deklaration fuer ungueltig
        // und wirft sie weg.
        //
        // Genau das ist am 06.09.2026 passiert: die Mails standen serifenlos,
        // die Rechnung als Serifenschrift, und der Unterschied war nirgends im
        // Code zu sehen — nur im Bild.
        //
        // CSS erlaubt mehrteilige Familiennamen auch ohne Anfuehrungszeichen
        // (`font-family: Segoe UI, Arial`), solange jeder Teil ein gueltiger
        // Bezeichner ist. Das trifft hier auf alle zu. Damit funktioniert
        // derselbe Wert in beiden Zusammenhaengen.
        return '-apple-system, Segoe UI, Roboto, Helvetica, Arial, DejaVu Sans, sans-serif';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name(),
            'ink' => $this->ink(),
            'accent' => $this->accent(),
            'paper' => $this->paper(),
            'muted' => $this->muted(),
            'logo' => $this->logoPath(),
        ];
    }
}
