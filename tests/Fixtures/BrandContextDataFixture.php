<?php

namespace Goldnead\BrandContext\Tests\Fixtures;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;

/**
 * Real-shaped brand data, insertable into any released schema.
 *
 * A migration test is only worth running against rows. An empty table will
 * accept any schema change at all — it is the rows that decide whether a unique
 * can be built, whether a foreign key can be added, and whether the guarantees
 * the siblings depend on still hold once the migration has run.
 *
 * The awkward part is that the schema those rows go into changes underneath
 * them: `brand_user` does not exist before 1.5.0, and a future release will add
 * columns this file has never heard of. A fixture with a fixed column list can
 * only seed one version of the database, and would quietly stop seeding the
 * interesting columns the moment a migration was added.
 *
 * This one asks the schema what it has. Every row is built at its widest, then
 * reduced to the columns that exist at the moment of the insert, and anything
 * NOT NULL that the fixture does not know about is filled generically and
 * uniquely, so a migration added next year is seeded without this file being
 * touched.
 *
 * Two details of the real data are deliberate. `create_brands_table` inserts
 * the default brand itself, with `insertOrIgnore`, so the handle `default` is
 * already taken before this fixture ever runs — nothing here tries to create
 * it, and the memberships below deliberately include some hanging off it,
 * because on a single-brand install that is the only brand there is. And the
 * user ids are a mix of the two shapes Statamic actually produces: numeric keys
 * from the eloquent users repository and uuids from the file one, plus one
 * sitting exactly on `user_id`'s 191-character cap, since a column that is
 * narrowed for an index has to be tested at the width it promises to keep.
 */
class BrandContextDataFixture
{
    /**
     * The cap `create_brand_user_table` puts on `user_id`. Duplicated here on
     * purpose: a fixture that read the width off the migration would stop
     * describing the data and start agreeing with the code under test.
     */
    public const USER_ID_MAX = 191;

    /**
     * The handle the create-migration reserves for the default brand. Nothing
     * in this fixture may claim it.
     */
    public const DEFAULT_HANDLE = 'default';

    /**
     * @var list<array{handle: string, name: string}>
     */
    public const BRANDS = [
        ['handle' => 'goldner', 'name' => 'Adrian Goldner'],
        ['handle' => 'chorwelt', 'name' => 'Chorwelt'],
        ['handle' => 'kursportal', 'name' => 'Kursportal'],
    ];

    /**
     * Who belongs where. `brand` is a handle from BRANDS or the reserved
     * default handle; `user` is an index into the user ids below.
     *
     * The shape matters: user 0 is in three brands, which is the case the
     * table exists for and the case a unique over `user_id` alone would
     * forbid; users 1 and 2 are in one each; and every brand has at least one
     * member, so no probe can pass by looking at an empty side of the join.
     *
     * @var list<array{brand: string, user: int}>
     */
    public const MEMBERSHIPS = [
        ['brand' => self::DEFAULT_HANDLE, 'user' => 0],
        ['brand' => self::DEFAULT_HANDLE, 'user' => 1],
        ['brand' => 'goldner', 'user' => 0],
        ['brand' => 'goldner', 'user' => 2],
        ['brand' => 'chorwelt', 'user' => 0],
        ['brand' => 'chorwelt', 'user' => 3],
        ['brand' => 'kursportal', 'user' => 1],
    ];

    /**
     * The index of the brand every handle probe is taken from, and of the
     * membership every duplicate probe is taken from.
     */
    public const PROBE_BRAND = 0;

    public const PROBE_MEMBERSHIP = 2;

    public function __construct(private Connection $connection) {}

    /**
     * Put one full generation of brands and memberships into the tables that
     * exist.
     *
     * Repeatable: pass a different `$batch` to add another generation without
     * colliding with the last one. Batch 0 is the fixture above verbatim, so
     * assertions can name a row by hand.
     *
     * @return int the number of rows written across all tables
     */
    public function seed(int $batch = 0): int
    {
        if (! $this->has('brands')) {
            return 0;
        }

        $written = 0;

        foreach (self::BRANDS as $index => $brand) {
            $this->insert('brands', [
                'handle' => self::handle($index, $batch),
                'name' => $brand['name'],
                // Never true. The default brand is created by the migration
                // itself and there is only ever one of it.
                'is_default' => false,
                'settings' => json_encode(['locale' => 'de', 'batch' => $batch]),
            ]);

            $written++;
        }

        if (! $this->has('brand_user')) {
            return $written;
        }

        foreach (self::MEMBERSHIPS as $membership) {
            $brandId = $this->brandId($membership['brand'], $batch);

            if ($brandId === null) {
                continue;
            }

            $this->insert('brand_user', [
                'brand_id' => $brandId,
                'user_id' => self::userId($membership['user'], $batch),
            ]);

            $written++;
        }

        return $written;
    }

    /**
     * The handle a given fixture brand carries in a given batch.
     */
    public static function handle(int $index, int $batch = 0): string
    {
        return self::BRANDS[$index]['handle'].($batch === 0 ? '' : '-b'.$batch);
    }

    /**
     * The handle to probe a given seed batch with.
     */
    public static function handleProbe(int $batch = 0): string
    {
        return self::handle(self::PROBE_BRAND, $batch);
    }

    /**
     * The user ids, in the two shapes Statamic's user repositories produce,
     * plus one at the column's cap.
     *
     * Batched by prefix rather than suffix for the uuid-shaped ones, so the
     * capped id stays exactly at the cap in every batch.
     */
    public static function userId(int $index, int $batch = 0): string
    {
        $ids = [
            // Eloquent users repository: the numeric primary key, stringified.
            '17',
            '204',
            // File users repository: a uuid, and no `users` table anywhere.
            '3f9c1a7e-5b28-4d61-9e0a-7c4b8d2f6a13',
            // The widest id this table is contracted to keep.
            self::cappedUserId(),
        ];

        $id = $ids[$index];

        if ($batch === 0) {
            return $id;
        }

        return $id === self::cappedUserId()
            ? substr('b'.$batch.'-'.$id, 0, self::USER_ID_MAX)
            : 'b'.$batch.'-'.$id;
    }

    public static function cappedUserId(): string
    {
        return substr(str_repeat('0123456789abcdef', 16), 0, self::USER_ID_MAX);
    }

    /**
     * The (brand handle, user id) pair to probe a given seed batch with. The
     * handle is the unbatched one from the fixture above — `brandId()` is what
     * resolves it against a batch, and doing it in one place keeps a probe from
     * being batched twice.
     *
     * @return array{brand: string, user: string}
     */
    public static function membershipProbe(int $batch = 0): array
    {
        return [
            'brand' => self::MEMBERSHIPS[self::PROBE_MEMBERSHIP]['brand'],
            'user' => self::userId(self::MEMBERSHIPS[self::PROBE_MEMBERSHIP]['user'], $batch),
        ];
    }

    /**
     * A brand the probe's user is *not* already a member of, so a cross-brand
     * insert proves the scoping rather than tripping over a row the fixture
     * already wrote. Unbatched, like `membershipProbe()`.
     */
    public static function membershipProbeOtherBrand(): string
    {
        return 'kursportal';
    }

    /**
     * How many rows every table this fixture writes to currently holds.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = [];

        foreach (self::tables() as $table) {
            if ($this->has($table)) {
                $counts[$table] = $this->connection->table($table)->count();
            }
        }

        return $counts;
    }

    /**
     * @return list<string>
     */
    public static function tables(): array
    {
        return ['brands', 'brand_user'];
    }

    /**
     * The id of a brand by handle, resolved against the batch it was written
     * in. The default brand belongs to no batch — the migration made it.
     */
    public function brandId(string $handle, int $batch = 0): ?int
    {
        if ($handle !== self::DEFAULT_HANDLE) {
            $handle = self::handle(self::indexOfHandle($handle), $batch);
        }

        $id = $this->connection->table('brands')->where('handle', $handle)->value('id');

        return $id === null ? null : (int) $id;
    }

    private static function indexOfHandle(string $handle): int
    {
        foreach (self::BRANDS as $index => $brand) {
            if ($brand['handle'] === $handle) {
                return $index;
            }
        }

        throw new \InvalidArgumentException("No fixture brand with the handle [{$handle}].");
    }

    private function has(string $table): bool
    {
        return $this->connection->getSchemaBuilder()->hasTable($table);
    }

    /**
     * Reduce a row to the columns the table has today, add timestamps, fill any
     * NOT NULL column the fixture does not know about, and insert.
     */
    private function insert(string $table, array $row): int
    {
        $columns = collect($this->connection->getSchemaBuilder()->getColumns($table))
            ->keyBy('name');

        $row = collect($row)
            ->only($columns->keys()->all())
            ->all();

        if ($columns->has('created_at')) {
            $row['created_at'] = now();
            $row['updated_at'] = now();
        }

        // A future table of this package's own that scopes by brand rather than
        // being one: same rule as in every sibling fixture.
        if ($columns->has('brand_id') && ! isset($row['brand_id'])) {
            $row['brand_id'] = $this->defaultBrandId();
        }

        foreach ($columns as $name => $column) {
            if (array_key_exists($name, $row)) {
                continue;
            }

            if (($column['auto_increment'] ?? false) || ($column['nullable'] ?? true) || ($column['default'] ?? null) !== null) {
                continue;
            }

            $row[$name] = $this->genericValueFor($column, $table, $name);
        }

        return (int) $this->connection->table($table)->insertGetId($row);
    }

    /**
     * A value for a NOT NULL column this fixture has never heard of.
     *
     * Unique per row, because a column added by a future migration is most
     * likely to be added together with a unique over it — which is the shape
     * this whole file exists to catch.
     */
    private function genericValueFor(array $column, string $table, string $name): string|int
    {
        $type = strtolower((string) ($column['type_name'] ?? $column['type'] ?? 'string'));

        return match (true) {
            str_contains($type, 'int') => random_int(1, PHP_INT_MAX),
            str_contains($type, 'bool') => 0,
            str_contains($type, 'date'), str_contains($type, 'time') => (string) now(),
            default => substr(hash('sha256', $table.$name.Str::uuid()), 0, 32),
        };
    }

    private function defaultBrandId(): ?int
    {
        return $this->connection->table('brands')->where('is_default', true)->value('id')
            ?? $this->connection->table('brands')->min('id');
    }
}
