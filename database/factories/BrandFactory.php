<?php

namespace Goldnead\BrandContext\Database\Factories;

use Goldnead\BrandContext\Models\Brand;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class BrandFactory extends Factory
{
    protected $model = Brand::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->company();

        return [
            'handle' => Str::slug($name),
            'name' => $name,
            'is_default' => false,
            'settings' => null,
        ];
    }

    public function default(): static
    {
        return $this->state(fn () => [
            'handle' => config('brand-context.default_handle', 'default'),
            'name' => config('brand-context.default_name', 'Default'),
            'is_default' => true,
        ]);
    }

    public function withApiToken(string $plainToken): static
    {
        return $this->state(fn () => [
            'settings' => ['api_token_hash' => hash('sha256', $plainToken)],
        ]);
    }
}
