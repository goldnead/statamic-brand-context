<?php

use Goldnead\BrandContext\Contracts\ProvidesMapObjects;
use Goldnead\BrandContext\Map\MapLookup;
use Goldnead\BrandContext\Map\MapMetric;
use Goldnead\BrandContext\Map\MapObject;
use Goldnead\BrandContext\Map\MapObjectRegistry;
use Goldnead\BrandContext\Map\MapPeriod;
use Illuminate\Support\Facades\Log;

class FakeMapProvider implements ProvidesMapObjects
{
    public function type(): string
    {
        return 'fake';
    }

    public function typeLabel(): string
    {
        return 'Fake';
    }

    public function objects(?string $query = null, int $limit = 50): array
    {
        $all = [new MapObject('fake', 'a', 'Alpha', '/cp/a', true), new MapObject('fake', 'b', 'Beta', null, null)];

        return array_values(array_filter($all, fn ($o) => $query === null || str_contains(strtolower($o->label), strtolower($query))));
    }

    public function find(string $id): ?MapObject
    {
        return collect($this->objects())->firstWhere('id', $id);
    }

    public function metric(string $id, MapPeriod $period): ?MapMetric
    {
        return $id === 'a' ? new MapMetric(7, 'Runs', $period) : null;
    }
}

class SameTypeOtherProvider extends FakeMapProvider {}

class ThrowingMapProvider extends FakeMapProvider
{
    public function type(): string
    {
        return 'boom';
    }

    public function find(string $id): ?MapObject
    {
        throw new RuntimeException('down');
    }

    public function metric(string $id, MapPeriod $period): ?MapMetric
    {
        throw new RuntimeException('down');
    }
}

it('is a singleton in the container', function () {
    expect(app(MapObjectRegistry::class))->toBe(app(MapObjectRegistry::class));
});

it('lists types, objects and filters by query', function () {
    $r = app(MapObjectRegistry::class);
    $r->register(FakeMapProvider::class);

    expect($r->types())->toBe(['fake' => 'Fake'])
        ->and($r->objects('fake'))->toHaveCount(2)
        ->and($r->objects('fake', 'alp'))->toHaveCount(1)
        ->and($r->objects('nope'))->toBe([]);
});

it('tells found, missing and unavailable apart', function () {
    $r = app(MapObjectRegistry::class);
    $r->register(FakeMapProvider::class);
    $r->register(ThrowingMapProvider::class);
    Log::spy();

    expect($r->lookup('fake', 'a')->status)->toBe(MapLookup::FOUND)
        ->and($r->lookup('fake', 'a')->object->label)->toBe('Alpha')
        ->and($r->lookup('fake', 'zzz')->status)->toBe(MapLookup::MISSING)
        ->and($r->lookup('absent', 'a')->status)->toBe(MapLookup::UNAVAILABLE)
        ->and($r->lookup('boom', 'a')->status)->toBe(MapLookup::UNAVAILABLE);

    Log::shouldHaveReceived('warning')->atLeast()->once();
});

it('returns a metric with what and over which period, or null', function () {
    $r = app(MapObjectRegistry::class);
    $r->register(FakeMapProvider::class);
    $r->register(ThrowingMapProvider::class);
    Log::spy();
    $period = MapPeriod::lastDays(30);

    $m = $r->metric('fake', 'a', $period);

    expect($m->value)->toBe(7)
        ->and($m->toArray()['what'])->toBe('Runs')
        ->and($m->toArray()['period'])->toBe('last 30 days')
        ->and($r->metric('fake', 'b', $period))->toBeNull()
        ->and($r->metric('boom', 'a', $period))->toBeNull()
        ->and($r->metric('absent', 'a', $period))->toBeNull();
});

it('refuses two providers for one type and non-providers', function () {
    $r = app(MapObjectRegistry::class);
    $r->register(FakeMapProvider::class);
    $r->register(FakeMapProvider::class);

    expect(fn () => $r->register(SameTypeOtherProvider::class))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $r->register(stdClass::class))->toThrow(InvalidArgumentException::class);
});

it('keeps the period one object for all cards', function () {
    $p = MapPeriod::lastDays(0);

    expect($p->days)->toBe(1)
        ->and($p->from->lt($p->to))->toBeTrue();
});
