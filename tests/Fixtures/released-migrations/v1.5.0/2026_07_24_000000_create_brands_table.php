<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('handle')->unique();
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        // Guarantee the default brand exists so single-brand installs and the
        // backfill of existing addon data always have a target.
        $handle = config('brand-context.default_handle', 'default');
        $name = config('brand-context.default_name', 'Default');

        DB::table('brands')->insertOrIgnore([
            'handle' => $handle,
            'name' => $name,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('brands');
    }
};
