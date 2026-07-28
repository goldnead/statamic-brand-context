<?php

use Illuminate\Support\Facades\DB;

/**
 * Adopted from statamic-notifications v1.0.4, where the original defect took a
 * release down on production: InnoDB refuses any index wider than 3072 bytes,
 * and under utf8mb4 a `varchar(255)` costs 1020 of them. A four-column unique
 * over such columns came to 3212 bytes and MySQL rejected the table with
 * SQLSTATE 1071 — while the suite stayed green throughout, because SQLite has
 * no key limit, no per-character byte cost and no fixed column widths. The very
 * arithmetic that fails on MySQL does not exist there to be tested.
 *
 * brand-context has the same exposure from v1.5.0 on: `brand_user` carries a
 * unique over a brand id and a user id, and a user id is a string.
 *
 * So this test does not ask the database. It compiles the addon's own
 * migrations through Laravel's MySQL grammar in pretend mode — no server, no
 * connection, nothing to install in CI — and measures the DDL MySQL would have
 * received. It reads the real migration files, so it cannot drift from them,
 * and it fails on the next oversized index rather than on the next deploy.
 */
const INNODB_MAX_KEY_BYTES = 3072;

it('keeps every index the migrations create inside the InnoDB key limit', function () {
    $schema = compileMigrationsForMysql();

    expect($schema['indexes'])->not->toBeEmpty();

    foreach ($schema['indexes'] as $index) {
        $bytes = 0;

        foreach ($index['columns'] as $column) {
            $width = $schema['columns'][$index['table']][$column]['bytes'] ?? null;

            expect($width)->not->toBeNull(
                "Index {$index['name']} covers unknown column {$column}."
            );

            $bytes += $width;
        }

        expect($bytes)->toBeLessThanOrEqual(
            INNODB_MAX_KEY_BYTES,
            "Index {$index['name']} on {$index['table']} needs {$bytes} bytes under utf8mb4; ".
            'InnoDB allows '.INNODB_MAX_KEY_BYTES.'. MySQL would refuse this migration with SQLSTATE 1071.'
        );
    }
});

it('still spends less than half the key limit, leaving room for another column', function () {
    // Being under the limit by accident is what made the original design
    // fragile, so the headroom is asserted rather than hoped for. It is also
    // why brand_user.user_id is a varchar(191) and not the default 255.
    $schema = compileMigrationsForMysql();

    foreach ($schema['indexes'] as $index) {
        $bytes = collect($index['columns'])->sum(fn ($column) => $schema['columns'][$index['table']][$column]['bytes'] ?? 0);

        expect($bytes)->toBeLessThan(
            INNODB_MAX_KEY_BYTES / 2,
            "Index {$index['name']} on {$index['table']} uses {$bytes} bytes — over half the limit, ".
            'so the next column added to it is likely to break the migration.'
        );
    }
});

it('enforces one membership per brand and user through a unique that actually binds', function () {
    // The NULL trap: a unique index does not constrain rows where one of its
    // columns is NULL. Both notifications and automations shipped a unique over
    // a nullable column and enforced nothing at all for exactly the rows that
    // mattered. Here the unique IS the membership, so a nullable column would
    // permit unlimited duplicate memberships.
    $schema = compileMigrationsForMysql();

    $unique = collect($schema['indexes'])->firstWhere('name', 'brand_user_unique');

    expect($unique)->not->toBeNull()
        ->and($unique['unique'])->toBeTrue()
        ->and($unique['columns'])->toBe(['brand_id', 'user_id']);

    foreach ($unique['columns'] as $column) {
        expect($schema['columns']['brand_user'][$column]['nullable'])->toBeFalse(
            "brand_user.{$column} is nullable, so brand_user_unique does not constrain rows where it is NULL."
        );
    }
});

it('indexes the reverse lookup, which every membership check performs', function () {
    // "Which brands is this user assigned to" runs on every includes() call —
    // it is how the transition rule decides whether a user is unassigned.
    $schema = compileMigrationsForMysql();

    expect(collect($schema['indexes'])->firstWhere('name', 'brand_user_user_index'))->not->toBeNull();
});

/**
 * Runs every migration in the addon against a MySQL connection that is never
 * opened, and returns the column definitions and index definitions MySQL would
 * see.
 *
 * @return array{columns: array<string, array<string, array{bytes: int, nullable: bool}>>, indexes: list<array{table: string, name: string, unique: bool, columns: list<string>}>}
 */
function compileMigrationsForMysql(): array
{
    config()->set('database.connections.key_length_probe', [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => '3306',
        'database' => 'key_length_probe',
        'username' => 'probe',
        'password' => '',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
    ]);

    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection('key_length_probe');

    // The probe must never reach a MySQL server, but Laravel asks the
    // connection for a PDO handle on paths that have nothing to do with
    // executing anything (reading the server version while preparing bindings
    // for the default-brand insert, for one). Handing it an in-memory SQLite
    // handle satisfies that without a network: pretend() still short-circuits
    // every statement, so nothing is ever prepared or run on it.
    DB::connection('key_length_probe')->setPdo(new PDO('sqlite::memory:'));

    try {
        // pretend() short-circuits every statement before a PDO instance is
        // needed, so this compiles the DDL without a server anywhere in sight.
        $queries = DB::connection('key_length_probe')->pretend(function () {
            foreach (glob(__DIR__.'/../../database/migrations/*.php') as $file) {
                (require $file)->up();
            }
        });
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('key_length_probe');
    }

    $columns = [];
    $indexes = [];

    foreach (array_column($queries, 'query') as $sql) {
        if (preg_match('/^create table `(\w+)` \((.*)\)(?: default character set| collate|$)/s', $sql, $match)) {
            foreach (splitTopLevel($match[2]) as $definition) {
                if (preg_match('/^`(\w+)` (.+)$/', trim($definition), $column)) {
                    $columns[$match[1]][$column[1]] = [
                        'bytes' => mysqlIndexBytes($column[2]),
                        // Laravel's MySQL grammar always emits `not null`
                        // explicitly, so its absence means nullable.
                        'nullable' => ! str_contains($column[2], 'not null'),
                    ];
                }
            }

            continue;
        }

        if (preg_match('/^alter table `(\w+)` add (unique|index) `(\w+)`\((.+)\)$/', $sql, $match)) {
            $indexes[] = [
                'table' => $match[1],
                'name' => $match[3],
                'unique' => $match[2] === 'unique',
                'columns' => array_map(
                    fn ($column) => trim($column, ' `'),
                    explode(',', $match[4])
                ),
            ];
        }
    }

    return ['columns' => $columns, 'indexes' => $indexes];
}

/** Splits a column list on commas that are not inside parentheses. */
function splitTopLevel(string $list): array
{
    $parts = [];
    $depth = 0;
    $buffer = '';

    foreach (str_split($list) as $character) {
        if ($character === '(') {
            $depth++;
        } elseif ($character === ')') {
            $depth--;
        }

        if ($character === ',' && $depth === 0) {
            $parts[] = $buffer;
            $buffer = '';

            continue;
        }

        $buffer .= $character;
    }

    return array_merge($parts, [$buffer]);
}

/** Worst-case bytes this column type occupies in an index under utf8mb4. */
function mysqlIndexBytes(string $type): int
{
    if (preg_match('/^(?:var)?char\((\d+)\)/', $type, $match)) {
        return (int) $match[1] * 4;
    }

    return match (true) {
        str_starts_with($type, 'tinyint') => 1,
        str_starts_with($type, 'smallint') => 2,
        str_starts_with($type, 'mediumint') => 3,
        str_starts_with($type, 'int') => 4,
        str_starts_with($type, 'bigint') => 8,
        str_starts_with($type, 'timestamp'), str_starts_with($type, 'datetime') => 8,
        str_starts_with($type, 'date') => 3,
        // Blobs and JSON cannot be indexed whole at all. Reported as oversized
        // so an index that reaches for one fails here rather than on MySQL.
        default => INNODB_MAX_KEY_BYTES + 1,
    };
}
