<?php

use Goldnead\BrandContext\Contracts\ProvidesMapEdges;
use Goldnead\BrandContext\Contracts\ProvidesMapObjects;
use Goldnead\BrandContext\Map\MapEdge;
use Goldnead\BrandContext\Map\MapMetric;
use Goldnead\BrandContext\Map\MapObject;
use Goldnead\BrandContext\Map\MapObjectRegistry;
use Goldnead\BrandContext\Map\MapPeriod;
use Illuminate\Support\Facades\Log;

class EdgesPlainProvider implements ProvidesMapObjects
{
    public function type(): string
    {
        return 'plain';
    }

    public function typeLabel(): string
    {
        return 'Plain';
    }

    public function objects(?string $query = null, int $limit = 50): array
    {
        return [];
    }

    public function find(string $id): ?MapObject
    {
        return null;
    }

    public function metric(string $id, MapPeriod $period): ?MapMetric
    {
        return null;
    }
}

class EdgesProvider extends EdgesPlainProvider implements ProvidesMapEdges
{
    public function type(): string
    {
        return 'edgy';
    }

    public function edges(): iterable
    {
        yield new MapEdge('edgy', 'a', 'edgy', 'b', 'waits_for', 'waits for', 'a field', true);
        yield new MapEdge('edgy', 'a', 'edgy', 'b', 'waits_for', 'waits for', 'a field', true);
        yield new MapEdge('edgy', 'a', 'edgy', 'gone', 'starts', 'starts', 'a node', false, 'gone-handle');
    }
}

class EdgesThrowingProvider extends EdgesProvider
{
    public function type(): string
    {
        return 'edgeboom';
    }

    public function edges(): iterable
    {
        throw new RuntimeException('down');
    }
}

it('collects edges from providers that offer them and de-duplicates the same relation', function () {
    $registry = new MapObjectRegistry(app());
    $registry->register(EdgesPlainProvider::class);
    $registry->register(EdgesProvider::class);

    $edges = $registry->edges();

    expect($edges)->toHaveCount(2)
        ->and($edges['edgy:a>waits_for>edgy:b']->dashed)->toBeTrue()
        ->and($edges['edgy:a>starts>edgy:gone']->toLabel)->toBe('gone-handle')
        ->and($edges['edgy:a>waits_for>edgy:b']->toArray()['derived_from'])->toBe('a field');
});

it('lets a throwing edge provider cost only its own edges', function () {
    Log::spy();
    $registry = new MapObjectRegistry(app());
    $registry->register(EdgesProvider::class);
    $registry->register(EdgesThrowingProvider::class);

    expect($registry->edges())->toHaveCount(2);
    Log::shouldHaveReceived('warning')->once();
});
