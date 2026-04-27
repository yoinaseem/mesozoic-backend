<?php

namespace Database\Seeders;

use App\Models\Hotel;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds one fixture user per role plus a demo hotel, and wires the
 * hotel-manager fixture to hotel #1 so scoped policies can be exercised
 * end-to-end during local development and integration tests.
 *
 * Each account's password is its own email address — easier to paste in
 * during manual API testing / frontend demos.
 *
 * Only invoked from DatabaseSeeder in the `local` and `testing` environments.
 */
class DevUsersSeeder extends Seeder
{
    public function run(): void
    {
        $hotel = Hotel::firstOrCreate(
            ['name' => 'Mesozoic Grand Hotel'],
            [
                'address'     => '1 Tyrannosaurus Way, Mesozoic Isle',
                'description' => 'Flagship hotel on the island. Used as the default fixture for scoped-RBAC tests.',
                'amenities'   => ['wifi', 'pool', 'restaurant'],
            ],
        );

        $fixtures = [
            ['email' => 'superadmin@mesozoic.test',    'name' => 'Super Admin',    'role' => 'superadmin'],
            ['email' => 'hotel-manager@mesozoic.test', 'name' => 'Hotel Manager',  'role' => 'hotel-manager'],
            ['email' => 'ferry-manager@mesozoic.test', 'name' => 'Ferry Manager',  'role' => 'ferry-manager'],
            ['email' => 'park-manager@mesozoic.test',  'name' => 'Park Manager',   'role' => 'park-manager'],
            ['email' => 'beach-manager@mesozoic.test', 'name' => 'Beach Manager',  'role' => 'beach-manager'],
            ['email' => 'customer@mesozoic.test',      'name' => 'Customer',       'role' => 'customer'],
        ];

        foreach ($fixtures as $fixture) {
            $user = User::updateOrCreate(
                ['email' => $fixture['email']],
                [
                    'name'     => $fixture['name'],
                    'password' => Hash::make($fixture['email']),
                ],
            );
            $user->syncRoles([$fixture['role']]);

            if ($fixture['role'] === 'hotel-manager') {
                $user->managedHotels()->syncWithoutDetaching([$hotel->id]);
            }
        }
    }
}
