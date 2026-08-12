<?php

use Goldnead\BrandContext\Contracts\SenderIdentityResolver;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\BrandContext\Sending\BrandMailer;
use Goldnead\BrandContext\Sending\BrandSenderIdentity;
use Goldnead\BrandContext\Sending\SaidRecently;
use Goldnead\BrandContext\Sending\SenderIdentity;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Who a brand's mail goes out as.
 *
 * This file is the single truth for a rule that used to live in four
 * byte-identical copies — statamic-marketing, -notifications,
 * -preference-center and -automations each carried its own `SenderIdentity`,
 * `BrandSenderIdentity` and `BrandMailer`. They had already begun to drift: the
 * marketing copy still accepted "a brand transport without a from-address",
 * which is exactly the pair a relay verifying sending domains per account
 * rejects. One rule, one place.
 *
 * `Mail::fake()` is deliberately NOT used. The fake records the name of the
 * mailer but never renders the message, and the From is decided during the
 * render — so the fake can prove the transport and not the sender, which is
 * exactly one half of the bug. Every brand gets its own `array` transport
 * instead, and the assertions read the real MIME message out of the transport
 * that actually accepted it.
 */
beforeEach(function (): void {
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();
    SaidRecently::forget();

    config()->set('mail.mailers.marke_a', ['transport' => 'array']);
    config()->set('mail.mailers.marke_b', ['transport' => 'array']);
    config()->set('mail.mailers.global', ['transport' => 'array']);
    config()->set('mail.default', 'global');
    config()->set('mail.from', ['address' => 'host@example.com', 'name' => 'Host']);

    $this->mails = fn (string $mailer) => Mail::mailer($mailer)->getSymfonyTransport()->messages();

    $this->brandA = Brand::create([
        'handle' => 'marke-a',
        'name' => 'Marke A',
        'settings' => ['mail' => [
            'from_address' => 'noreply@marke-a.test',
            'from_name' => 'Marke A Post',
            'mailer' => 'marke_a',
            'locale' => 'de',
        ]],
    ]);

    $this->brandB = Brand::create([
        'handle' => 'marke-b',
        'name' => 'Marke B',
        'settings' => ['mail' => [
            'from_address' => 'noreply@marke-b.test',
            'mailer' => 'marke_b',
        ]],
    ]);

    $this->mailable = fn () => new class extends Mailable
    {
        public function build(): self
        {
            // Exactly how the addons' mailables behave: a From already put
            // there by BrandMailer is not overwritten.
            if (empty($this->from)) {
                $this->from('paket@example.com', 'Paket');
            }

            return $this->subject('Betreff')->html('<p>Hallo.</p>');
        }
    };
});

it('sends brand A as brand A and brand B as brand B, in one process, A first', function (): void {
    $mailer = app(BrandMailer::class);

    expect($mailer->send($this->brandA->id, 'anna@example.com', null, ($this->mailable)()))->toBeTrue();
    expect($mailer->send($this->brandB->id, 'bert@example.com', null, ($this->mailable)()))->toBeTrue();

    expect(($this->mails)('marke_a'))->toHaveCount(1)
        ->and(($this->mails)('marke_b'))->toHaveCount(1)
        ->and(($this->mails)('global'))->toHaveCount(0);

    expect(($this->mails)('marke_a')[0]->getOriginalMessage()->getFrom()[0]->getAddress())
        ->toBe('noreply@marke-a.test');

    expect(($this->mails)('marke_b')[0]->getOriginalMessage()->getFrom()[0]->getAddress())
        ->toBe('noreply@marke-b.test');
});

it('never burns a brand\'s From into a cached mailer instance', function (): void {
    // The regression this guards: Laravel's MailManager reads `mail.from` the
    // first time a mailer name is resolved and keeps it on the instance for the
    // life of the process (`alwaysFrom`). Overriding `mail.from.*` inside a
    // send window therefore escapes the window — the first brand to send would
    // leave its address standing for every later message through that transport
    // that sets no From of its own.
    app(BrandMailer::class)->send($this->brandA->id, 'anna@example.com', null, ($this->mailable)());

    Mail::mailer('marke_a')->raw('Hallo', function ($message): void {
        $message->to('irgendwer@example.com')->subject('Ohne eigenen Absender');
    });

    $mails = ($this->mails)('marke_a');

    expect($mails)->toHaveCount(2)
        ->and($mails[1]->getOriginalMessage()->getFrom()[0]->getAddress())
        ->toBe('host@example.com');
});

it('leaves the mail config exactly as it found it', function (): void {
    $before = [config('mail.default'), config('mail.from.address'), config('mail.from.name')];

    app(BrandMailer::class)->send($this->brandA->id, 'anna@example.com', null, ($this->mailable)());

    expect([config('mail.default'), config('mail.from.address'), config('mail.from.name')])->toBe($before);
});

it('changes nothing for a brand that declares no mail settings', function (): void {
    $plain = Brand::create(['handle' => 'schlicht', 'name' => 'Schlicht']);

    expect(app(BrandMailer::class)->send($plain->id, 'carla@example.com', null, ($this->mailable)()))->toBeTrue();

    // The configured default transport, and the mailable's own From — the
    // single-brand install is untouched by all of this.
    expect(($this->mails)('global'))->toHaveCount(1)
        ->and(($this->mails)('marke_a'))->toHaveCount(0);

    expect(($this->mails)('global')[0]->getOriginalMessage()->getFrom()[0]->getAddress())
        ->toBe('paket@example.com');
});

it('says so once per window when a brand under multi-brand declares nothing', function (): void {
    Log::spy();

    $plain = Brand::create(['handle' => 'schlicht', 'name' => 'Schlicht']);

    app(BrandMailer::class)->send($plain->id, 'carla@example.com', null, ($this->mailable)());
    app(BrandMailer::class)->send($plain->id, 'dora@example.com', null, ($this->mailable)());

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $m) => str_contains($m, 'schlicht') && str_contains($m, 'settings.mail'))
        ->once();
});

it('stays silent about an undeclared brand on a single-brand install', function (): void {
    config()->set('brand-context.multi_brand', false);
    app('brand-context')->forget();

    Log::spy();

    $plain = Brand::create(['handle' => 'schlicht', 'name' => 'Schlicht']);

    app(BrandMailer::class)->send($plain->id, 'carla@example.com', null, ($this->mailable)());

    Log::shouldNotHaveReceived('warning');
});

it('refuses a brand that declares a mailer but no from-address', function (): void {
    Log::spy();

    $halb = Brand::create([
        'handle' => 'halb',
        'name' => 'Halb',
        'settings' => ['mail' => ['mailer' => 'marke_b']],
    ]);

    // The pair is the whole point. Sending over the brand's transport with the
    // host-wide From is the 12.08. incident with the halves swapped.
    expect(app(BrandMailer::class)->send($halb->id, 'dora@example.com', null, ($this->mailable)()))->toBeFalse();

    expect(($this->mails)('marke_b'))->toHaveCount(0)
        ->and(($this->mails)('global'))->toHaveCount(0);

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $m) => str_contains($m, 'halb') && str_contains($m, 'from_address'))
        ->once();
});

it('refuses a mailer name that config/mail.php does not define', function (): void {
    $tippfehler = Brand::create([
        'handle' => 'tippfehler',
        'name' => 'Tippfehler',
        'settings' => ['mail' => ['from_address' => 'x@example.com', 'mailer' => 'scaleway_typo']],
    ]);

    expect(app(BrandMailer::class)->maySend($tippfehler->id))->toBeFalse();
    expect(app(BrandMailer::class)->send($tippfehler->id, 'dora@example.com', null, ($this->mailable)()))->toBeFalse();

    expect(($this->mails)('global'))->toHaveCount(0);
});

it('refuses the transport-without-address pair even from a host resolver', function (): void {
    // The invariant lives in the value object, not in the bundled resolver, so
    // a host binding its own answer cannot reintroduce the failure by accident.
    $identity = SenderIdentity::of('marke_a', null, 'Wer auch immer');

    expect($identity->maySend())->toBeFalse()
        ->and($identity->refusal)->toContain('marke_a');
});

it('treats an address without a mailer as legitimate and silent', function (): void {
    $nurAdresse = Brand::create([
        'handle' => 'nur-adresse',
        'name' => 'Nur Adresse',
        'settings' => ['mail' => ['from_address' => 'post@nur-adresse.test']],
    ]);

    expect(app(BrandMailer::class)->send($nurAdresse->id, 'dora@example.com', null, ($this->mailable)()))->toBeTrue();

    // The default transport — "my domain is verified in the account the app
    // already uses" — with the brand's own address on the message.
    expect(($this->mails)('global'))->toHaveCount(1)
        ->and(($this->mails)('global')[0]->getOriginalMessage()->getFrom()[0]->getAddress())
        ->toBe('post@nur-adresse.test');
});

it('falls back to the brand name when no from_name is declared', function (): void {
    app(BrandMailer::class)->send($this->brandB->id, 'bert@example.com', null, ($this->mailable)());

    expect(($this->mails)('marke_b')[0]->getOriginalMessage()->getFrom()[0]->getName())->toBe('Marke B');
});

it('carries the brand locale on the mailable, not on the app', function (): void {
    app()->setLocale('en');

    $mailable = ($this->mailable)();

    app(BrandMailer::class)->send($this->brandA->id, 'anna@example.com', null, $mailable);

    expect($mailable->locale)->toBe('de')
        ->and(app()->getLocale())->toBe('en');
});

it('refuses a queued mailable rather than losing the identity on the way', function (): void {
    $queued = new class extends Mailable implements ShouldQueue
    {
        public function build(): self
        {
            return $this->subject('Egal')->html('<p>Egal</p>');
        }
    };

    expect(fn () => app(BrandMailer::class)->send($this->brandA->id, 'anna@example.com', null, $queued))
        ->toThrow(LogicException::class);
});

it('keeps a callback from overwriting the brand identity on a raw send', function (): void {
    $sent = app(BrandMailer::class)->sendRaw(
        $this->brandA->id,
        '<p>Hallo.</p>',
        null,
        function ($message): void {
            $message->to('anna@example.com')->subject('Betreff')->from('fremd@example.invalid');
        },
    );

    expect($sent)->toBeTrue();

    expect(($this->mails)('marke_a')[0]->getOriginalMessage()->getFrom()[0]->getAddress())
        ->toBe('noreply@marke-a.test');
});

it('lets the host application answer the question its own way', function (): void {
    app()->bind(SenderIdentityResolver::class, fn () => new class implements SenderIdentityResolver
    {
        public function resolve(?int $brandId): SenderIdentity
        {
            return SenderIdentity::of('marke_b', 'host@example.com', 'Host');
        }
    });

    app(BrandMailer::class)->send($this->brandA->id, 'anna@example.com', null, ($this->mailable)());

    expect(($this->mails)('marke_b'))->toHaveCount(1)
        ->and(($this->mails)('marke_a'))->toHaveCount(0);
});

it('lets a package override only the default transport', function (): void {
    // marketing.sending.mailer is a documented setting that predates all of
    // this. A package keeps it by overriding one hook; the brand still wins.
    $mailer = new class(app(BrandSenderIdentity::class)) extends BrandMailer
    {
        protected function transport(SenderIdentity $identity): ?string
        {
            return $identity->mailer ?? 'marke_b';
        }
    };

    $plain = Brand::create(['handle' => 'schlicht', 'name' => 'Schlicht']);

    $mailer->send($plain->id, 'carla@example.com', null, ($this->mailable)());
    $mailer->send($this->brandA->id, 'anna@example.com', null, ($this->mailable)());

    expect(($this->mails)('marke_b'))->toHaveCount(1)
        ->and(($this->mails)('marke_a'))->toHaveCount(1)
        ->and(($this->mails)('global'))->toHaveCount(0);
});
