<?php

namespace Database\Factories;

use App\Models\FerryType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FerryType>
 */
class FerryTypeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Standard', 'Air Conditioned', 'Premium', 'Economy']).' '.fake()->unique()->word(),
            'description' => fake()->sentence(),
            'image' => null,
            'capacity' => fake()->numberBetween(20, 200),
            'price' => fake()->randomFloat(2, 10, 250),
        ];
    }
}
