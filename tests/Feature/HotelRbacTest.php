<?php

use App\Models\Hotel;
use App\Models\User;

use function Pest\Laravel\getJson;

test('public can list hotels without auth', function () {
    Hotel::factory()->count(2)->create();

    getJson('/api/hotels')->assertOk();
});

test('public can view a single hotel without auth', function () {
    $hotel = Hotel::factory()->create();

    getJson("/api/hotels/{$hotel->id}")->assertOk();
});

test('unauthenticated user cannot create a hotel', function () {
    $this->postJson('/api/hotels', [
        'name'        => 'Test',
        'address'     => 'Nowhere',
        'description' => 'A test hotel',
    ])->assertUnauthorized();
});

test('customer cannot create a hotel', function () {
    $customer = User::factory()->create();
    $customer->assignRole('customer');

    $this->actingAs($customer)
        ->postJson('/api/hotels', [
            'name'        => 'Test',
            'address'     => 'Nowhere',
            'description' => 'A test hotel',
        ])
        ->assertForbidden();
});

test('hotel-manager cannot create a hotel (superadmin-only)', function () {
    $manager = User::factory()->create();
    $manager->assignRole('hotel-manager');

    $this->actingAs($manager)
        ->postJson('/api/hotels', [
            'name'        => 'Test',
            'address'     => 'Nowhere',
            'description' => 'A test hotel',
        ])
        ->assertForbidden();
});

test('superadmin can create a hotel', function () {
    $admin = User::factory()->create();
    $admin->assignRole('superadmin');

    $this->actingAs($admin)
        ->postJson('/api/hotels', [
            'name'        => 'Admin Hotel',
            'address'     => 'Central',
            'description' => 'Built by superadmin',
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Admin Hotel');
});

test('unassigned hotel-manager cannot update a hotel', function () {
    $hotel   = Hotel::factory()->create();
    $manager = User::factory()->create();
    $manager->assignRole('hotel-manager');

    $this->actingAs($manager)
        ->putJson("/api/hotels/{$hotel->id}", ['name' => 'Hijacked'])
        ->assertForbidden();
});

test('assigned hotel-manager can update their hotel', function () {
    $hotel   = Hotel::factory()->create();
    $manager = User::factory()->create();
    $manager->assignRole('hotel-manager');
    $manager->managedHotels()->attach($hotel);

    $this->actingAs($manager)
        ->putJson("/api/hotels/{$hotel->id}", ['name' => 'Renamed'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Renamed');
});

test('assigned hotel-manager cannot update an unrelated hotel', function () {
    $owned  = Hotel::factory()->create();
    $other  = Hotel::factory()->create();
    $manager = User::factory()->create();
    $manager->assignRole('hotel-manager');
    $manager->managedHotels()->attach($owned);

    $this->actingAs($manager)
        ->putJson("/api/hotels/{$other->id}", ['name' => 'Nope'])
        ->assertForbidden();
});

test('superadmin can update any hotel', function () {
    $hotel = Hotel::factory()->create();
    $admin = User::factory()->create();
    $admin->assignRole('superadmin');

    $this->actingAs($admin)
        ->putJson("/api/hotels/{$hotel->id}", ['name' => 'Admin Edit'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Admin Edit');
});

test('hotel-manager cannot delete a hotel (superadmin-only)', function () {
    $hotel   = Hotel::factory()->create();
    $manager = User::factory()->create();
    $manager->assignRole('hotel-manager');
    $manager->managedHotels()->attach($hotel);

    $this->actingAs($manager)
        ->deleteJson("/api/hotels/{$hotel->id}")
        ->assertForbidden();
});

test('superadmin can archive a hotel (soft-delete)', function () {
    $hotel = Hotel::factory()->create();
    $admin = User::factory()->create();
    $admin->assignRole('superadmin');

    $this->actingAs($admin)
        ->deleteJson("/api/hotels/{$hotel->id}")
        ->assertNoContent();

    expect($hotel->fresh()->trashed())->toBeTrue();
});

test('archived hotel no longer appears in public listing', function () {
    $live     = Hotel::factory()->create(['name' => 'Live']);
    $archived = Hotel::factory()->create(['name' => 'Archived']);
    $archived->delete();

    $response = $this->getJson('/api/hotels')->assertOk();

    $names = collect($response->json('data'))->pluck('name')->all();
    expect($names)->toContain('Live')->not->toContain('Archived');
});

test('archive is blocked when hotel has upcoming confirmed bookings', function () {
    $hotel = Hotel::factory()->create();
    $type  = $hotel->roomTypes()->create(['name' => 'Standard', 'capacity' => 2, 'price' => 100]);
    $hotel->rooms()->create(['room_type_id' => $type->id, 'room_no' => '101']);

    $guest       = \App\Models\User::factory()->create();
    $reservation = \App\Models\Reservation::create(['user_id' => $guest->id]);
    \App\Models\RoomBooking::create([
        'reservation_id'  => $reservation->id,
        'hotel_id'        => $hotel->id,
        'room_type_id'    => $type->id,
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
        ->deleteJson("/api/hotels/{$hotel->id}")
        ->assertStatus(409)
        ->assertJsonPath('blocking_bookings', 1);

    expect($hotel->fresh()->trashed())->toBeFalse();
});

test('archive is allowed once all bookings are historical', function () {
    $hotel = Hotel::factory()->create();
    $type  = $hotel->roomTypes()->create(['name' => 'Standard', 'capacity' => 2, 'price' => 100]);
    $hotel->rooms()->create(['room_type_id' => $type->id, 'room_no' => '101']);

    // Past booking — check_out_date is yesterday, doesn't block.
    $guest       = \App\Models\User::factory()->create();
    $reservation = \App\Models\Reservation::create(['user_id' => $guest->id]);
    \App\Models\RoomBooking::create([
        'reservation_id'  => $reservation->id,
        'hotel_id'        => $hotel->id,
        'room_type_id'    => $type->id,
        'status'          => 'confirmed',
        'check_in_date'   => now()->subDays(3)->toDateString(),
        'check_out_date'  => now()->subDay()->toDateString(),
        'guests'          => 2,
        'price_per_night' => 100,
        'nights'          => 2,
        'total_price'     => 200,
    ]);

    $admin = User::factory()->create();
    $admin->assignRole('superadmin');

    $this->actingAs($admin)
        ->deleteJson("/api/hotels/{$hotel->id}")
        ->assertNoContent();
});

test('superadmin can restore an archived hotel and children cascade back', function () {
    $hotel = Hotel::factory()->create();
    $type  = $hotel->roomTypes()->create(['name' => 'Standard', 'capacity' => 2, 'price' => 100]);
    $room  = $hotel->rooms()->create(['room_type_id' => $type->id, 'room_no' => '101']);

    $hotel->delete();

    expect($hotel->fresh()->trashed())->toBeTrue();
    expect($type->fresh()->trashed())->toBeTrue();
    expect($room->fresh()->trashed())->toBeTrue();

    $admin = User::factory()->create();
    $admin->assignRole('superadmin');

    $this->actingAs($admin)
        ->postJson("/api/hotels/{$hotel->id}/restore")
        ->assertOk()
        ->assertJsonPath('data.id', $hotel->id);

    expect($hotel->fresh()->trashed())->toBeFalse();
    expect($type->fresh()->trashed())->toBeFalse();
    expect($room->fresh()->trashed())->toBeFalse();
});

test('non-superadmin cannot restore a hotel', function () {
    $hotel = Hotel::factory()->create();
    $hotel->delete();

    $manager = User::factory()->create();
    $manager->assignRole('hotel-manager');
    $manager->managedHotels()->attach($hotel);

    $this->actingAs($manager)
        ->postJson("/api/hotels/{$hotel->id}/restore")
        ->assertForbidden();
});
