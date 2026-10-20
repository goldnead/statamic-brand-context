<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The durable record of every connection the map wrote or removed, for sites
 * without statamic-activity. One row per action, the user as an id only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('map_connection_audits', function (Blueprint $table) {
            $table->id();
            $table->string('event', 64);
            $table->string('user_id', 64)->nullable();
            $table->string('from_type', 64);
            $table->string('from_id', 191);
            $table->string('to_type', 64);
            $table->string('to_id', 191);
            $table->string('kind', 64);
            $table->text('consequence');
            $table->text('before');
            $table->text('after');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('map_connection_audits');
    }
};
