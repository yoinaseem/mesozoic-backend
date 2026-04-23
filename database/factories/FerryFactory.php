<?php

namespace Database\Factories;

use App\Models\Ferry;
use App\Models\FerryType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ferry>
 */
class FerryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ferry_type_id' => FerryType::factory(),
            'name' => 'Isla Express '.fake()->unique()->numerify('###'),
        ];
    }
}
