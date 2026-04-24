<?php

use App\Models\User;

use function Pest\Laravel\getJson;

test('unauthenticated user cannot list users', function () {
    getJson('/api/users')->assertUnauthorized();
});

test('customer cannot list users', function () {
    $customer = User::factory()->create();
    $customer->assignRole('customer');

    $this->actingAs($customer)
        ->getJson('/api/users')
        ->assertForbidden();
});

test('superadmin can list users', function () {
    $admin = User::factory()->create();
    $admin->assignRole('superadmin');

    $this->actingAs($admin)
        ->getJson('/api/users')
        ->assertOk();
});

test('superadmin can archive a user (soft-delete)', function () {
    $admin = User::factory()->create();
    $admin->assignRole('superadmin');
    $victim = User::factory()->create();

    $this->actingAs($admin)
        ->deleteJson("/api/users/{$victim->id}")
        ->assertNoContent();

    expect($victim->fresh()->trashed())->toBeTrue();
});

test('archived user does not appear in superadmin index', function () {
    $admin = User::factory()->create();
    $admin->assignRole('superadmin');
    $archived = User::factory()->create(['name' => 'Archived Guest']);
    $archived->delete();

    $response = $this->actingAs($admin)->getJson('/api/users')->assertOk();

    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->not->toContain($archived->id);
});

test('archive is blocked when user has upcoming confirmed room booking', function () {
    $admin = User::factory()->create();
    $admin->assignRole('superadmin');

    $guest = User::factory()->create();
    $hotel = \App\Models\Hotel::factory()->create();
    $type  = $hotel->roomTypes()->create(['name' => 'Standard', 'capacity' => 2, 'price' => 100]);
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

    $this->actingAs($admin)
        ->deleteJson("/api/users/{$guest->id}")
        ->assertStatus(409)
        ->assertJsonPath('blocking_bookings', 1);

    expect($guest->fresh()->trashed())->toBeFalse();
});

test('archive succeeds once the users only booking is historical', function () {
    $admin = User::factory()->create();
    $admin->assignRole('superadmin');

    $guest = User::factory()->create();
    $hotel = \App\Models\Hotel::factory()->create();
    $type  = $hotel->roomTypes()->create(['name' => 'Standard', 'capacity' => 2, 'price' => 100]);
    $reservation = \App\Models\Reservation::create(['user_id' => $guest->id]);
    \App\Models\RoomBooking::create([
        'reservation_id'  => $reservation->id,
        'hotel_id'        => $hotel->id,
        'room_type_id'    => $type->id,
        'status'          => 'confirmed',
        'check_in_date'   => now()->subDays(5)->toDateString(),
        'check_out_date'  => now()->subDays(3)->toDateString(),
        'guests'          => 2,
        'price_per_night' => 100,
        'nights'          => 2,
        'total_price'     => 200,
    ]);

    $this->actingAs($admin)
        ->deleteJson("/api/users/{$guest->id}")
        ->assertNoContent();

    expect($guest->fresh()->trashed())->toBeTrue();
});

test('superadmin can restore an archived user', function () {
    $admin = User::factory()->create();
    $admin->assignRole('superadmin');

    $victim = User::factory()->create();
    $victim->delete();

    $this->actingAs($admin)
        ->postJson("/api/users/{$victim->id}/restore")
        ->assertOk()
        ->assertJsonPath('data.id', $victim->id);

    expect($victim->fresh()->trashed())->toBeFalse();
});

test('non-superadmin cannot restore a user', function () {
    $customer = User::factory()->create();
    $customer->assignRole('customer');

    $victim = User::factory()->create();
    $victim->delete();

    $this->actingAs($customer)
        ->postJson("/api/users/{$victim->id}/restore")
        ->assertForbidden();
});

test('archived user cannot log in', function () {
    $user = User::factory()->create([
        'email'    => 'archived@example.com',
        'password' => bcrypt('correct-password'),
    ]);
    $user->assignRole('customer');
    $user->delete();

    // Login fails via the standard validation path — archived users are
    // invisible to the User model's global scope, so auth can't find them
    // and returns "credentials are incorrect" (422) exactly like an unknown
    // email. The exact shape matters less than: they can't log in.
    $this->postJson('/api/auth/login', [
        'email'    => 'archived@example.com',
        'password' => 'correct-password',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

test('historical room booking still serializes its archived user (withTrashed)', function () {
    $admin = User::factory()->create();
    $admin->assignRole('superadmin');

    $guest = User::factory()->create();
    $hotel = \App\Models\Hotel::factory()->create();
    $type  = $hotel->roomTypes()->create(['name' => 'Standard', 'capacity' => 2, 'price' => 100]);
    $reservation = \App\Models\Reservation::create(['user_id' => $guest->id]);
    $booking = \App\Models\RoomBooking::create([
        'reservation_id'  => $reservation->id,
        'hotel_id'        => $hotel->id,
        'room_type_id'    => $type->id,
        'status'          => 'confirmed',
        'check_in_date'   => now()->subDays(10)->toDateString(),
        'check_out_date'  => now()->subDays(8)->toDateString(),
        'guests'          => 2,
        'price_per_night' => 100,
        'nights'          => 2,
        'total_price'     => 200,
    ]);
    $guest->delete();

    $response = $this->actingAs($admin)
        ->getJson("/api/room-bookings/{$booking->id}")
        ->assertOk();

    expect($response->json('data.reservation.user'))->not->toBeNull()
        ->and($response->json('data.reservation.user.id'))->toBe($guest->id);
});

test('user can view themselves', function () {
    $user = User::factory()->create();
    $user->assignRole('customer');

    $this->actingAs($user)
        ->getJson("/api/users/{$user->id}")
        ->assertOk();
});

test('user cannot view another user', function () {
    $user = User::factory()->create();
    $user->assignRole('customer');
    $other = User::factory()->create();

    $this->actingAs($user)
        ->getJson("/api/users/{$other->id}")
        ->assertForbidden();
});

test('user can update themselves', function () {
    $user = User::factory()->create();
    $user->assignRole('customer');

    $this->actingAs($user)
        ->putJson("/api/users/{$user->id}", ['name' => 'New Name'])
        ->assertOk()
        ->assertJsonPath('data.name', 'New Name');
});

test('user cannot delete themselves', function () {
    $user = User::factory()->create();
    $user->assignRole('customer');

    $this->actingAs($user)
        ->deleteJson("/api/users/{$user->id}")
        ->assertForbidden();
});
