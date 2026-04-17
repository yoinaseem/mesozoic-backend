<?php

namespace Database\Factories;

use App\Models\ThemePark;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ThemePark>
 */
class ThemeParkFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company().' Park',
            'images' => [fake()->imageUrl(), fake()->imageUrl()],
            'description' => fake()->paragraph(),
            'capacity' => fake()->numberBetween(500, 50000),
            'price' => fake()->randomFloat(2, 25, 200),
            'contact_email' => fake()->safeEmail(),
            'contact_phone' => fake()->phoneNumber(),
        ];
    }
}
