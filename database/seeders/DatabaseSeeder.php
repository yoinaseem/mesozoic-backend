<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        // Fixture users and hotels are only seeded in development / test environments.
        // Production seeds the roles and permissions catalogue only.
        if (app()->environment(['local', 'testing'])) {
            $this->call(DevUsersSeeder::class);
        }
    }
}
