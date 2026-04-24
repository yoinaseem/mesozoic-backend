<?php

use App\Models\Hotel;
use App\Models\RoomType;
use App\Models\User;

use function Pest\Laravel\getJson;

function makeRoomType(Hotel $hotel, array $overrides = []): RoomType
{
    return $hotel->roomTypes()->create(array_merge([
        'name'     => 'Standard',
        'capacity' => 2,
        'price'    => 100,
    ], $overrides));
}

test('public can list room types', function () {
    $hotel = Hotel::factory()->create();
    makeRoomType($hotel);

    getJson("/api/hotels/{$hotel->id}/room-types")->assertOk();
});

test('unauthenticated user cannot create a room type', function () {
    $hotel = Hotel::factory()->create();

    $this->postJson("/api/hotels/{$hotel->id}/room-types", ['name' => 'Deluxe'])
        ->assertUnauthorized();
});

test('customer cannot create a room type', function () {
    $hotel    = Hotel::factory()->create();
    $customer = User::factory()->create();
    $customer->assignRole('customer');

    $this->actingAs($customer)
        ->postJson("/api/hotels/{$hotel->id}/room-types", ['name' => 'Deluxe'])
        ->assertForbidden();
});

test('unassigned hotel-manager cannot create a room type', function () {
    $hotel   = Hotel::factory()->create();
    $manager = User::factory()->create();
    $manager->assignRole('hotel-manager');

    $this->actingAs($manager)
        ->postJson("/api/hotels/{$hotel->id}/room-types", ['name' => 'Deluxe'])
        ->assertForbidden();
});

test('assigned hotel-manager can create a room type', function () {
    $hotel   = Hotel::factory()->create();
    $manager = User::factory()->create();
    $manager->assignRole('hotel-manager');
    $manager->managedHotels()->attach($hotel);

    $this->actingAs($manager)
        ->postJson("/api/hotels/{$hotel->id}/room-types", [
            'name'     => 'Deluxe',
            'capacity' => 3,
            'price'    => 250,
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Deluxe');
});

test('assigned hotel-manager can update room type in their hotel', function () {
    $hotel    = Hotel::factory()->create();
    $roomType = makeRoomType($hotel);
    $manager  = User::factory()->create();
    $manager->assignRole('hotel-manager');
    $manager->managedHotels()->attach($hotel);

    $this->actingAs($manager)
        ->putJson("/api/hotels/{$hotel->id}/room-types/{$roomType->id}", ['name' => 'Renamed'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Renamed');
});

test('hotel-manager cannot update room type in unrelated hotel', function () {
    $owned    = Hotel::factory()->create();
    $other    = Hotel::factory()->create();
    $roomType = makeRoomType($other);
    $manager  = User::factory()->create();
    $manager->assignRole('hotel-manager');
    $manager->managedHotels()->attach($owned);

    $this->actingAs($manager)
        ->putJson("/api/hotels/{$other->id}/room-types/{$roomType->id}", ['name' => 'Nope'])
        ->assertForbidden();
});

test('superadmin can archive any room type (soft-delete)', function () {
    $hotel    = Hotel::factory()->create();
    $roomType = makeRoomType($hotel);
    $admin    = User::factory()->create();
    $admin->assignRole('superadmin');

    $this->actingAs($admin)
        ->deleteJson("/api/hotels/{$hotel->id}/room-types/{$roomType->id}")
        ->assertNoContent();

    expect($roomType->fresh()->trashed())->toBeTrue();
});

test('archive is blocked when room type has upcoming confirmed bookings', function () {
    $hotel    = Hotel::factory()->create();
    $roomType = makeRoomType($hotel);
    $hotel->rooms()->create(['room_type_id' => $roomType->id, 'room_no' => '101']);

    $guest       = User::factory()->create();
    $reservation = \App\Models\Reservation::create(['user_id' => $guest->id]);
    \App\Models\RoomBooking::create([
        'reservation_id'  => $reservation->id,
        'hotel_id'        => $hotel->id,
        'room_type_id'    => $roomType->id,
        'status'          => 'confirmed',
        'check_in_date'   => now()->addDays(3)->toDateString(),
        'check_out_date'  => now()->addDays(5)->toDateString(),
        'guests'          => 2,
        'price_per_night' => 100,
        'nights'          => 2,
        'total_price'     => 200,
    ]);

    $admin = User::factory()->create();
    $admin->assignRole('superadmin');

    $this->actingAs($admin)
        ->deleteJson("/api/hotels/{$hotel->id}/room-types/{$roomType->id}")
        ->assertStatus(409)
        ->assertJsonPath('blocking_bookings', 1);

    expect($roomType->fresh()->trashed())->toBeFalse();
});

test('archived room type cannot be restored while parent hotel is archived', function () {
    $hotel    = Hotel::factory()->create();
    $roomType = makeRoomType($hotel);
    $hotel->delete();

    $admin = User::factory()->create();
    $admin->assignRole('superadmin');

    $this->actingAs($admin)
        ->postJson("/api/hotels/{$hotel->id}/room-types/{$roomType->id}/restore")
        ->assertStatus(409);
});
