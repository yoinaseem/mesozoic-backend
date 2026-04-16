<?php

namespace Database\Factories;

use App\Models\Hotel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Hotel>
 */
class HotelFactory extends Factory
{
    protected $model = Hotel::class;

    public function definition(): array
    {
        return [
            'name'        => fake()->company() . ' Hotel',
            'address'     => fake()->address(),
            'description' => fake()->paragraph(),
            'amenities'   => fake()->randomElements(
                ['wifi', 'pool', 'gym', 'spa', 'parking', 'restaurant', 'bar'],
                fake()->numberBetween(2, 5),
            ),
            'image'       => null,
        ];
    }
}
