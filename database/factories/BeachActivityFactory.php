<?php

namespace Database\Factories;

use App\Models\BeachActivity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BeachActivity>
 */
class BeachActivityFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'price' => fake()->randomFloat(2, 10, 500),
            'capacity' => fake()->numberBetween(1, 50),
            'duration' => fake()->numberBetween(30, 240),
            'image' => null,
        ];
    }
}
