<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function superadmin(): static
    {
        return $this->afterCreating(fn (User $user) => $user->assignRole('superadmin'));
    }

    public function hotelManager(): static
    {
        return $this->afterCreating(fn (User $user) => $user->assignRole('hotel-manager'));
    }

    public function ferryManager(): static
    {
        return $this->afterCreating(fn (User $user) => $user->assignRole('ferry-manager'));
    }

    public function parkManager(): static
    {
        return $this->afterCreating(fn (User $user) => $user->assignRole('park-manager'));
    }

    public function beachManager(): static
    {
        return $this->afterCreating(fn (User $user) => $user->assignRole('beach-manager'));
    }

    public function customer(): static
    {
        return $this->afterCreating(fn (User $user) => $user->assignRole('customer'));
    }
}
