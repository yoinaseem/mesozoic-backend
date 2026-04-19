<?php

namespace Database\Factories;

use App\Models\ParkActivity;
use App\Models\ThemePark;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ParkActivity>
 */
class ParkActivityFactory extends Factory
{
    public function definition(): array
    {
        return [
            'park_id' => ThemePark::factory(),
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'price' => fake()->randomFloat(2, 5, 150),
            'image' => null,
            'duration' => fake()->numberBetween(15, 180),
            'max_capacity' => fake()->numberBetween(5, 100),
            'is_all_day' => false,
        ];
    }
}
