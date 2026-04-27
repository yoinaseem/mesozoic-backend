<?php

namespace Database\Seeders;

use App\Models\Hotel;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Adds two extra hotels alongside the "Mesozoic Grand Hotel" fixture seeded
 * by DevUsersSeeder, gives each hotel three room types (Standard / Deluxe /
 * Suite), and stocks each room type with six numbered rooms. Existing
 * hotel-manager fixture is pivoted to all hotels so multi-property scopes
 * can be exercised.
 *
 * Idempotent: re-running won't create duplicates.
 */
class HotelCatalogueSeeder extends Seeder
{
    public function run(): void
    {
        $hotels = [
            'Mesozoic Grand Hotel' => [
                'address' => '1 Tyrannosaurus Way, Mesozoic Isle',
                'description' => 'Flagship hotel on the island. Used as the default fixture for scoped-RBAC tests.',
                'amenities' => ['wifi', 'pool', 'restaurant'],
            ],
            'Cretaceous Bay Resort' => [
                'address' => '42 Velociraptor Cove, Mesozoic Isle',
                'description' => 'Beachfront resort on the eastern shore with private bay access.',
                'amenities' => ['wifi', 'pool', 'spa', 'restaurant', 'bar'],
            ],
            'Triassic Heights Lodge' => [
                'address' => '88 Brachiosaurus Ridge, Mesozoic Isle',
                'description' => 'Mountain lodge above the canopy with panoramic plateau views.',
                'amenities' => ['wifi', 'restaurant', 'gym', 'parking'],
            ],
        ];

        $roomTypeBlueprints = [
            ['name' => 'Standard', 'capacity' => 2, 'price' => 120.00, 'amenities' => ['wifi', 'tv', 'air_conditioning']],
            ['name' => 'Deluxe',   'capacity' => 3, 'price' => 220.00, 'amenities' => ['wifi', 'tv', 'air_conditioning', 'minibar', 'balcony']],
            ['name' => 'Suite',    'capacity' => 4, 'price' => 380.00, 'amenities' => ['wifi', 'tv', 'air_conditioning', 'minibar', 'balcony', 'lounge', 'jacuzzi']],
        ];

        foreach ($hotels as $name => $attrs) {
            $hotel = Hotel::firstOrCreate(['name' => $name], $attrs);

            foreach ($roomTypeBlueprints as $i => $blueprint) {
                $type = RoomType::firstOrCreate(
                    ['hotel_id' => $hotel->id, 'name' => $blueprint['name']],
                    [
                        'description' => "{$blueprint['name']} room at {$hotel->name}.",
                        'capacity'    => $blueprint['capacity'],
                        'price'       => $blueprint['price'],
                        'amenities'   => $blueprint['amenities'],
                    ],
                );

                // Floor-prefixed room numbers: type 0 → 101..106, type 1 → 201..206, type 2 → 301..306.
                $floor = $i + 1;
                for ($n = 1; $n <= 6; $n++) {
                    $roomNo = (string) ($floor * 100 + $n);
                    Room::firstOrCreate(
                        ['hotel_id' => $hotel->id, 'room_no' => $roomNo],
                        ['room_type_id' => $type->id],
                    );
                }
            }
        }

        // Extend the hotel-manager fixture's pivot to every hotel so multi-
        // property management can be demoed end-to-end.
        $manager = User::where('email', 'hotel-manager@mesozoic.test')->first();
        if ($manager !== null) {
            $manager->managedHotels()->syncWithoutDetaching(Hotel::pluck('id')->all());
        }
    }
}
