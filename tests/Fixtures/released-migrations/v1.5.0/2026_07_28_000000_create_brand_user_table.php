<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which Control Panel users belong to which brand.
 *
 * Three decisions are baked into this table and none of them are cosmetic.
 *
 * **`user_id` is a string and carries no foreign key.** A Statamic user is not
 * necessarily a database row: with the file users repository the user lives in
 * `users/<email>.yaml` and its id is a uuid, and there is no `users` table at
 * all. A foreign key here would make the addon uninstallable on exactly the
 * installs Statamic ships by default. The id is the one thing both drivers
 * agree on — `Statamic\Facades\User::all()` returns `$user->id()` as a string
 * for the file driver (uuid) and the eloquent driver (numeric key) alike, and
 * `goldnead/statamic-identity-contracts` already stringifies it the same way
 * (`Identity::$userId`). Referential integrity for the user side therefore has
 * to be handled by the application, not by the schema; the brand side keeps
 * its foreign key, because brands are always rows in this package's own table.
 *
 * **A user may belong to several brands.** Hence a table rather than a column
 * on the user: the same person can work in two brands, and the single-brand
 * case is simply one row. The reverse modelling (a `brand_id` on the user)
 * cannot express the first case without a migration, so this is the direction
 * that keeps both open.
 *
 * **Both columns are NOT NULL.** A unique index does not constrain NULLs —
 * MySQL and SQLite both allow unlimited rows where one indexed column is NULL,
 * so a nullable `user_id` would let `brand_user_unique` sit there enforcing
 * nothing at all. That defect has already been found twice in this addon
 * family (notifications, automations), and `tests/Unit/IndexKeyLengthTest.php`
 * asserts the nullability of these columns for that reason.
 *
 * `user_id` is capped at 191 characters rather than the default 255: a
 * `varchar(255)` costs 1020 bytes in an index under utf8mb4, and the unique
 * spans it together with `brand_id`. At 191 the key is 772 bytes, comfortably
 * inside the 3072-byte InnoDB limit and with room for another column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->string('user_id', 191);
            $table->timestamps();

            // The membership itself. NOT NULL on both sides, or this enforces
            // nothing for the rows that matter.
            $table->unique(['brand_id', 'user_id'], 'brand_user_unique');

            // "Which brands does this user belong to" is asked on every
            // membership check, including the one that decides whether a user
            // is unassigned. It must not be a table scan.
            $table->index('user_id', 'brand_user_user_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_user');
    }
};
