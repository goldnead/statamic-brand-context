<?php

use Goldnead\BrandContext\Tests\MigrationPathTestCase;
use Goldnead\BrandContext\Tests\MultiBrandBootTestCase;
use Goldnead\BrandContext\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

// The migration tests drive migrations by hand, against a database of their
// own — see MigrationPathTestCase. The rest of the suite meets a database that
// RefreshDatabase has already migrated to head, which is the one shape a
// migration can never be wrong about.
uses(MigrationPathTestCase::class)->in('Migrations');

// The queue suite needs multi-brand ON BEFORE the provider boots — the queue
// hook is registered once, under that flag, and a test that flipped it in
// beforeEach would pass against an application in which it was never wired.
uses(MultiBrandBootTestCase::class)->in('Queue');
