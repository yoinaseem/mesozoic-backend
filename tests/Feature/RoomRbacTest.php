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

test('superadmin can archive any room (soft-delete)', function () {
    $hotel = Hotel::factory()->create();
    $room  = makeRoom($hotel, '401');
    $admin = User::factory()->create();
    $admin->assignRole('superadmin');

    $this->actingAs($admin)
        ->deleteJson("/api/hotels/{$hotel->id}/rooms/{$room->id}")
        ->assertNoContent();

    expect($room->fresh()->trashed())->toBeTrue();
});

test('archive of a room is blocked when it has an upcoming confirmed booking', function () {
    $hotel = Hotel::factory()->create();
    $room  = makeRoom($hotel, '501');
    $type  = $room->roomType;

    $guest       = User::factory()->create();
    $reservation = \App\Models\Reservation::create(['user_id' => $guest->id]);
    \App\Models\RoomBooking::create([
        'reservation_id'  => $reservation->id,
        'hotel_id'        => $hotel->id,
        'room_type_id'    => $type->id,
        'room_id'         => $room->id,
        'status'          => 'confirmed',
        'check_in_date'   => now()->addDays(2)->toDateString(),
        'check_out_date'  => now()->addDays(4)->toDateString(),
        'guests'          => 2,
        'price_per_night' => 100,
        'nights'          => 2,
        'total_price'     => 200,
    ]);

    $admin = User::factory()->create();
    $admin->assignRole('superadmin');

    $this->actingAs($admin)
        ->deleteJson("/api/hotels/{$hotel->id}/rooms/{$room->id}")
        ->assertStatus(409)
        ->assertJsonPath('blocking_bookings', 1);

    expect($room->fresh()->trashed())->toBeFalse();
});

test('room_no can be reused after the previous room is archived', function () {
    $hotel = Hotel::factory()->create();
    $manager = User::factory()->create();
    $manager->assignRole('hotel-manager');
    $manager->managedHotels()->attach($hotel);

    $old = makeRoom($hotel, '777');
    $old->delete();

    $this->actingAs($manager)
        ->postJson("/api/hotels/{$hotel->id}/rooms", [
            'room_type_id' => $old->room_type_id,
            'room_no'      => '777',
        ])
        ->assertCreated()
        ->assertJsonPath('data.room_no', '777');
});

test('duplicate active room_no in the same hotel is rejected', function () {
    $hotel = Hotel::factory()->create();
    $manager = User::factory()->create();
    $manager->assignRole('hotel-manager');
    $manager->managedHotels()->attach($hotel);

    $existing = makeRoom($hotel, '888');

    $this->actingAs($manager)
        ->postJson("/api/hotels/{$hotel->id}/rooms", [
            'room_type_id' => $existing->room_type_id,
            'room_no'      => '888',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['room_no']);
});
