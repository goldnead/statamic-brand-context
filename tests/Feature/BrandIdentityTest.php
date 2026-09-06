<?php

namespace Goldnead\BrandContext\Tests\Feature;

use Goldnead\BrandContext\Models\Brand;
use Goldnead\BrandContext\Support\BrandIdentity;
use Goldnead\BrandContext\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Wie eine Marke aussieht — die eine Quelle fuer Mail und Rechnung.
 *
 * Die Regeln hier sind keine Geschmacksfragen. Sie stehen so im Ticket
 * `backlog-suite-mails-und-rechnung-branding`, und sie haben alle denselben
 * Grund: eine Mail und eine Rechnung duerfen beim Anzeigen NICHTS nachladen.
 * Mail-Clients blockieren entfernte Bilder, und eine Rechnung muss zehn Jahre
 * lesbar bleiben.
 */
class BrandIdentityTest extends TestCase
{
    /**
     * Eine Marke mit dieser Erscheinung.
     *
     * `updateOrCreate`, weil die Migration von brand-context bereits eine
     * Standardmarke anlegt. Ein `create` mit demselben Handle laeuft in die
     * Unique-Bedingung — und ein Test, der an seiner eigenen Kulisse
     * scheitert, sagt nichts ueber die Sache.
     */
    private function marke(array $identity = [], string $handle = 'default'): Brand
    {
        return Brand::updateOrCreate(
            ['handle' => $handle],
            [
                'name' => 'Testmarke',
                'is_default' => true,
                'settings' => $identity === [] ? null : ['identity' => $identity],
            ],
        );
    }

    #[Test]
    public function without_anything_configured_it_still_answers(): void
    {
        // Eine Installation ohne Einstellungen bekommt ein lesbares Dokument,
        // keine leere Stelle. Sonst waere die erste Rechnung einer frischen
        // Installation weiss auf weiss.
        $identitaet = BrandIdentity::for(null);

        $this->assertSame(BrandIdentity::DEFAULTS['ink'], $identitaet->ink());
        $this->assertSame(BrandIdentity::DEFAULTS['paper'], $identitaet->paper());
        $this->assertNotSame('', $identitaet->name());
    }

    #[Test]
    public function the_brand_beats_the_config_beats_the_default(): void
    {
        config(['brand-context.identity' => ['ink' => '#111111', 'accent' => '#222222']]);

        $identitaet = BrandIdentity::for($this->marke(['ink' => '#333333']));

        $this->assertSame('#333333', $identitaet->ink(), 'Die Marke schlaegt die Config.');
        $this->assertSame('#222222', $identitaet->accent(), 'Die Config schlaegt die Vorgabe.');
        $this->assertSame(BrandIdentity::DEFAULTS['paper'], $identitaet->paper(), 'Sonst die Vorgabe.');
    }

    #[Test]
    public function an_empty_setting_is_not_an_answer(): void
    {
        // Ein `''` in einer Einstellung ist keine Farbe, sondern eine
        // vergessene Zeile. Wuerde es gewinnen, waere die Schrift unsichtbar.
        $identitaet = BrandIdentity::for($this->marke(['ink' => '', 'paper' => '   ']));

        $this->assertSame(BrandIdentity::DEFAULTS['ink'], $identitaet->ink());
        $this->assertSame(BrandIdentity::DEFAULTS['paper'], $identitaet->paper());
    }

    #[Test]
    public function something_that_is_not_a_colour_falls_back(): void
    {
        // Zwei Gruende, und der zweite wiegt schwerer: ein Tippfehler soll eine
        // Rechnung nicht unlesbar machen — und der Wert landet in einem
        // `style="…"`, ist also ein Weg, fremdes CSS in eine Mail zu schreiben.
        foreach (['rot', 'red', '#12', 'javascript:alert(1)', '#1234567', 'red;}body{display:none'] as $unsinn) {
            $identitaet = BrandIdentity::for($this->marke(['ink' => $unsinn], 'm'.md5($unsinn)));

            $this->assertSame(
                BrandIdentity::DEFAULTS['ink'],
                $identitaet->ink(),
                "'{$unsinn}' darf nicht als Farbe durchgehen."
            );
        }
    }

    #[Test]
    public function a_short_hex_is_a_colour(): void
    {
        $this->assertSame('#abc', BrandIdentity::for($this->marke(['ink' => '#abc']))->ink());
    }

    #[Test]
    public function a_logo_url_is_refused(): void
    {
        // DIE wichtigste Regel. Ein Logo per https:// ist in der Mail beim
        // ersten Oeffnen ein leerer Kasten (Clients blockieren entfernte
        // Bilder) und in einer Rechnung ein Risiko auf zehn Jahre. Lieber kein
        // Logo als ein kaputtes.
        foreach ([
            'https://example.test/logo.svg',
            'http://example.test/logo.svg',
            '//example.test/logo.svg',
            'data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=',
            'DATA:image/png;base64,AAAA',
        ] as $entfernt) {
            $identitaet = BrandIdentity::for($this->marke(['logo' => $entfernt], 'm'.md5($entfernt)));

            $this->assertNull($identitaet->logoPath(), "'{$entfernt}' ist kein Dateipfad.");
            $this->assertTrue(BrandIdentity::logoUrlLooking($entfernt));
        }
    }

    #[Test]
    public function a_path_that_leads_nowhere_is_the_same_as_none(): void
    {
        $identitaet = BrandIdentity::for($this->marke(['logo' => '/gibt/es/nicht/logo.svg']));

        $this->assertNull($identitaet->logoPath());
        $this->assertNull($identitaet->logoSvg());
    }

    #[Test]
    public function an_svg_on_disk_comes_back_as_text(): void
    {
        $pfad = sys_get_temp_dir().'/brand-identity-test-'.getmypid().'.svg';
        file_put_contents($pfad, "<?xml version=\"1.0\"?>\n<!-- ein Kommentar -->\n<svg xmlns=\"http://www.w3.org/2000/svg\"><rect/></svg>");

        try {
            $identitaet = BrandIdentity::for($this->marke(['logo' => $pfad]));

            $this->assertSame($pfad, $identitaet->logoPath());

            $svg = $identitaet->logoSvg();

            $this->assertIsString($svg);
            // Was vor dem `<svg` steht, gehoert nicht mitten in ein
            // HTML-Dokument — eine XML-Deklaration dort bricht das Rendern.
            $this->assertStringStartsWith('<svg', $svg);
            $this->assertStringNotContainsString('<?xml', $svg);
        } finally {
            @unlink($pfad);
        }
    }

    #[Test]
    public function a_non_svg_is_not_inlined(): void
    {
        // Ein PNG hier einzubetten hiesse, es als data:-URI ins HTML zu
        // schreiben. Fuer die Mail ist der CID-Anhang der Weg, und der braucht
        // den Pfad, nicht diesen Text.
        $pfad = sys_get_temp_dir().'/brand-identity-test-'.getmypid().'.png';
        file_put_contents($pfad, 'kein svg');

        try {
            $identitaet = BrandIdentity::for($this->marke(['logo' => $pfad]));

            $this->assertSame($pfad, $identitaet->logoPath(), 'Fuer den CID-Anhang bleibt der Pfad da.');
            $this->assertNull($identitaet->logoSvg(), 'Aber inline geht nur SVG.');
        } finally {
            @unlink($pfad);
        }
    }

    #[Test]
    public function the_font_stack_carries_no_webfont(): void
    {
        // Die Hausschriften gibt es im Postfach nicht, und ein @font-face waere
        // derselbe externe Abruf, den das Logo hier nicht machen darf.
        $stack = BrandIdentity::for(null)->fontStack();

        $this->assertStringNotContainsString('http', $stack);
        $this->assertStringNotContainsString('@font-face', $stack);
        $this->assertStringNotContainsString('Bricolage', $stack);
        $this->assertStringNotContainsString('Hanken', $stack);
        // Ohne sie verlieren die PHP-Druckmaschinen die Umlaute.
        $this->assertStringContainsString('DejaVu Sans', $stack);
    }

    #[Test]
    public function the_brand_name_stands_in_for_the_wordmark(): void
    {
        $this->assertSame('Testmarke', BrandIdentity::for($this->marke())->name());
        $this->assertSame('Eigene Wortmarke', BrandIdentity::for($this->marke(['name' => 'Eigene Wortmarke'], 'zwei'))->name());
    }

    #[Test]
    public function a_configured_wordmark_beats_the_brand_row_name(): void
    {
        // Der Fehler, den dieser Test festhaelt, war im Bild sofort zu sehen:
        // die Bestellbestaetigung des Suite-Ladens trug „Default" statt
        // „adriangoldner.dev". Die Marke in der Datenbank heisst so, und der
        // ABGELEITETE Name hatte die ausdrueckliche Config geschlagen.
        //
        // Die Regel: was jemand hingeschrieben hat, gewinnt gegen das, was wir
        // uns hergeleitet haben.
        config(['brand-context.identity' => ['name' => 'adriangoldner.dev']]);

        $identitaet = BrandIdentity::for($this->marke([], 'default'));

        $this->assertSame('adriangoldner.dev', $identitaet->name());
    }

    #[Test]
    public function an_explicit_name_on_the_brand_still_beats_the_config(): void
    {
        // Die Gegenrichtung, damit die Korrektur oben nicht zu weit greift:
        // `settings.identity.name` ist ebenfalls hingeschrieben, also gewinnt
        // sie. Nur der abgeleitete `brands.name` rutscht ans Ende.
        config(['brand-context.identity' => ['name' => 'aus der config']]);

        $this->assertSame(
            'an der marke',
            BrandIdentity::for($this->marke(['name' => 'an der marke'], 'drei'))->name(),
        );
    }

    #[Test]
    public function unknown_keys_are_ignored(): void
    {
        // Was nicht zur Erscheinung gehoert, faellt raus. Sonst waere das
        // JSON-Feld ein Ort, an dem irgendwer irgendwas ablegt, das dann in
        // einer Rechnung landet.
        $identitaet = BrandIdentity::for($this->marke(['ink' => '#abcdef', 'schuhgroesse' => '43']));

        $this->assertSame('#abcdef', $identitaet->ink());
        $this->assertArrayNotHasKey('schuhgroesse', $identitaet->toArray());
        $this->assertSame(['name', 'ink', 'accent', 'paper', 'muted', 'logo'], array_keys($identitaet->toArray()));
    }
}
