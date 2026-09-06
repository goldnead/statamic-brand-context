<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Control Panel setting overrides, one row per changed key, per brand.
 *
 * **One key per row, not a JSON blob.** `brands.settings` already exists and
 * carries exactly one namespace today (`settings.mail`). It stays where it is
 * and is not touched here. It is the wrong shape for this: sixteen addons
 * writing their own section of one JSON column means two concurrent saves read
 * the same document, each writes back its own version, and the loser is
 * overwritten completely without anything noticing. At one writer that was
 * theoretical. At sixteen it is the first silent data loss.
 *
 * **`namespace` is the addon, `key` is the dotted config path below it.** They
 * are separate columns rather than one joined string so a namespace can be
 * read, migrated or dropped as a unit — uninstalling an addon means deleting
 * its namespace, not pattern-matching a prefix.
 *
 * **Overrides only.** A row exists only for a value somebody actually changed.
 * Everything unset keeps following `config/<addon>.php`, so a package upgrade
 * still moves the defaults and a site that never opened the screen behaves
 * exactly like one running a release from before the screen existed. Saving a
 * value back to the packaged default deletes the row rather than pinning it.
 *
 * **Column widths.** The unique index spans all three columns and has to fit
 * InnoDB's 3072-byte limit under utf8mb4, where every character costs four
 * bytes. `brand_id` is 8, `namespace` at 64 is 256, `key` at 191 is 764 — 1028
 * bytes total, with room to spare. The default `varchar(255)` on both strings
 * would cost 2048 and leave the index one added column away from failing to
 * build on MySQL while continuing to work on SQLite, which is a defect this
 * addon family has shipped before — see `IndexKeyLengthTest` in this repo's
 * test suite. Named as text rather than imported: `.gitattributes` marks
 * `/tests` as `export-ignore`, so a `use` of a test class would point at
 * nothing in every installation that came through Composer.
 *
 * **Both string columns are NOT NULL.** A unique index does not constrain
 * NULLs: MySQL and SQLite both allow unlimited rows where an indexed column is
 * NULL, so a nullable `namespace` would leave `brand_settings_unique`
 * enforcing nothing. `value` *is* nullable, because null is a real setting
 * value here — an unset retention means "same as the default", which is not
 * the same as zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_settings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();

            $table->string('namespace', 64);
            $table->string('key', 191);

            // `json`, not `text`: the values are booleans, integers, strings
            // and lists, and the column has to hand each back as what it was.
            $table->json('value')->nullable();

            $table->timestamps();

            $table->unique(['brand_id', 'namespace', 'key'], 'brand_settings_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_settings');
    }
};
