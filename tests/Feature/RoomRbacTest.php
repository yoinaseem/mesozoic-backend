<?php

use App\Models\Hotel;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;

use function Pest\Laravel\getJson;

function makeRoom(Hotel $hotel, string $roomNo = '101'): Room
{
    $roomType = $hotel->roomTypes()->create([
        'name'     => 'Standard ' . $roomNo,
        'capacity' => 2,
        'price'    => 100,
    ]);

    return $hotel->rooms()->create([
        'room_type_id' => $roomType->id,
        'room_no'      => $roomNo,
    ]);
}

test('public can list rooms', function () {
    $hotel = Hotel::factory()->create();
    makeRoom($hotel);

    getJson("/api/hotels/{$hotel->id}/rooms")->assertOk();
});

test('unauthenticated user cannot create a room', function () {
    $hotel = Hotel::factory()->create();
    $roomType = $hotel->roomTypes()->create(['name' => 'Standard', 'capacity' => 2, 'price' => 100]);

    $this->postJson("/api/hotels/{$hotel->id}/rooms", [
        'room_type_id' => $roomType->id,
        'room_no'      => '201',
    ])->assertUnauthorized();
});

test('customer cannot create a room', function () {
    $hotel    = Hotel::factory()->create();
    $roomType = $hotel->roomTypes()->create(['name' => 'Standard', 'capacity' => 2, 'price' => 100]);
    $customer = User::factory()->create();
    $customer->assignRole('customer');

    $this->actingAs($customer)
        ->postJson("/api/hotels/{$hotel->id}/rooms", [
            'room_type_id' => $roomType->id,
            'room_no'      => '201',
        ])
        ->assertForbidden();
});

test('unassigned hotel-manager cannot create a room', function () {
    $hotel    = Hotel::factory()->create();
    $roomType = $hotel->roomTypes()->create(['name' => 'Standard', 'capacity' => 2, 'price' => 100]);
    $manager  = User::factory()->create();
    $manager->assignRole('hotel-manager');

    $this->actingAs($manager)
        ->postJson("/api/hotels/{$hotel->id}/rooms", [
            'room_type_id' => $roomType->id,
            'room_no'      => '201',
        ])
        ->assertForbidden();
});

test('assigned hotel-manager can create a room in their hotel', function () {
    $hotel    = Hotel::factory()->create();
    $roomType = $hotel->roomTypes()->create(['name' => 'Standard', 'capacity' => 2, 'price' => 100]);
    $manager  = User::factory()->create();
    $manager->assignRole('hotel-manager');
    $manager->managedHotels()->attach($hotel);

    $this->actingAs($manager)
        ->postJson("/api/hotels/{$hotel->id}/rooms", [
            'room_type_id' => $roomType->id,
            'room_no'      => '201',
        ])
        ->assertCreated()
        ->assertJsonPath('data.room_no', '201');
});

test('hotel-manager cannot update a room in an unrelated hotel', function () {
    $owned   = Hotel::factory()->create();
    $other   = Hotel::factory()->create();
    $room    = makeRoom($other, '301');
    $manager = User::factory()->create();
    $manager->assignRole('hotel-manager');
    $manager->managedHotels()->attach($owned);

    $this->actingAs($manager)
        ->putJson("/api/hotels/{$other->id}/rooms/{$room->id}", ['room_no' => '999'])
        ->assertForbidden();
});

test('assigned hotel-manager can update a room in their hotel', function () {
    $hotel   = Hotel::factory()->create();
    $room    = makeRoom($hotel, '101');
    $manager = User::factory()->create();
    $manager->assignRole('hotel-manager');
    $manager->managedHotels()->attach($hotel);

    $this->actingAs($manager)
        ->putJson("/api/hotels/{$hotel->id}/rooms/{$room->id}", ['room_no' => '999'])
        ->assertOk()
        ->assertJsonPath('data.room_no', '999');
});

test('superadmin can delete any room', function () {
    $hotel = Hotel::factory()->create();
    $room  = makeRoom($hotel, '401');
    $admin = User::factory()->create();
    $admin->assignRole('superadmin');

    $this->actingAs($admin)
        ->deleteJson("/api/hotels/{$hotel->id}/rooms/{$room->id}")
        ->assertNoContent();
});
