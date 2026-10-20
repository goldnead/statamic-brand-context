<?php

use Goldnead\BrandContext\Map\MapObjectRegistry;
use Goldnead\BrandContext\Map\MapPeriod;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/MapObjectRegistryTest.php';

class DetailsMapProvider extends FakeMapProvider
{
    public function type(): string
    {
        return 'rich';
    }

    public function details(string $id, MapPeriod $period): ?array
    {
        return match ($id) {
            'a' => [
                ['kind' => 'facts', 'title' => 'Price', 'rows' => [
                    ['label' => 'Price', 'value' => '29,00 EUR'],
                    ['label' => 'Page', 'value' => '/x', 'href' => 'javascript:alert(1)'],
                    ['label' => '', 'value' => 'no label'],
                ]],
                ['kind' => 'list', 'items' => array_map(fn ($i) => ['title' => "Mail $i", 'meta' => 'after 1 day', 'image' => $i === 0 ? '/img.png' : null], range(0, 29)), 'more' => 5],
                ['kind' => 'unknown', 'x' => 1],
                ['kind' => 'list', 'items' => []],
                'not a block',
            ],
            'boom' => throw new RuntimeException('down'),
            default => null,
        };
    }
}

it('hands the details of a provider through, cleaned', function () {
    $r = app(MapObjectRegistry::class);
    $r->register(DetailsMapProvider::class);
    $blocks = $r->details('rich', 'a', MapPeriod::lastDays(30));

    expect($blocks)->toHaveCount(2)
        ->and($blocks[0]['kind'])->toBe('facts')
        ->and($blocks[0]['rows'])->toHaveCount(2)
        ->and($blocks[0]['rows'][1]['href'])->toBeNull()
        ->and($blocks[1]['items'])->toHaveCount(12)
        ->and($blocks[1]['more'])->toBe(5 + 18)
        ->and($blocks[1]['items'][0]['image'])->toBe('/img.png');
});

it('answers null for providers without details, unknown ids, failures and absent types', function () {
    $r = app(MapObjectRegistry::class);
    $r->register(DetailsMapProvider::class);
    $r->register(FakeMapProvider::class);
    Log::spy();
    $period = MapPeriod::lastDays(30);

    expect($r->details('fake', 'a', $period))->toBeNull()
        ->and($r->details('rich', 'zzz', $period))->toBeNull()
        ->and($r->details('rich', 'boom', $period))->toBeNull()
        ->and($r->details('absent', 'a', $period))->toBeNull();
});
