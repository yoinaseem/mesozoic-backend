<?php

use App\Models\ThemePark;
use App\Models\User;

use function Pest\Laravel\getJson;

test('public can list theme parks without auth', function () {
    ThemePark::factory()->count(2)->create();

    getJson('/api/theme-parks')->assertOk();
});

test('public can view a single theme park without auth', function () {
    $park = ThemePark::factory()->create();

    getJson("/api/theme-parks/{$park->id}")->assertOk();
});

test('unauthenticated user cannot create a theme park', function () {
    $this->postJson('/api/theme-parks', [
        'name' => 'Jurassic World',
        'images' => ['https://example.com/a.jpg'],
        'description' => 'Roarsome day out',
        'capacity' => 8000,
        'price' => 89.99,
        'contact_email' => 'hello@park.test',
        'contact_phone' => '+1-555-0100',
    ])->assertUnauthorized();
});

test('customer cannot create a theme park', function () {
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)
        ->postJson('/api/theme-parks', [
            'name' => 'Jurassic World',
            'images' => ['https://example.com/a.jpg'],
            'description' => 'Roarsome day out',
            'capacity' => 8000,
            'price' => 89.99,
            'contact_email' => 'hello@park.test',
            'contact_phone' => '+1-555-0100',
        ])
        ->assertForbidden();
});

test('park-manager can create a theme park', function () {
    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->postJson('/api/theme-parks', [
            'name' => 'Jurassic World',
            'images' => ['https://example.com/a.jpg'],
            'description' => 'Roarsome day out',
            'capacity' => 8000,
            'price' => 89.99,
            'contact_email' => 'hello@park.test',
            'contact_phone' => '+1-555-0100',
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Jurassic World');
});

test('superadmin can create a theme park', function () {
    $admin = User::factory()->superadmin()->create();

    $this->actingAs($admin)
        ->postJson('/api/theme-parks', [
            'name' => 'Cretaceous Coast',
            'images' => [],
            'description' => 'Admin-built park',
            'capacity' => 5000,
            'price' => 75,
            'contact_email' => 'admin@park.test',
            'contact_phone' => '+1-555-0200',
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Cretaceous Coast');
});

test('park-manager can update a theme park', function () {
    $park = ThemePark::factory()->create();
    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->putJson("/api/theme-parks/{$park->id}", ['name' => 'Renamed Park'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Renamed Park');
});

test('customer cannot update a theme park', function () {
    $park = ThemePark::factory()->create();
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)
        ->putJson("/api/theme-parks/{$park->id}", ['name' => 'No Access'])
        ->assertForbidden();
});

test('superadmin can update any theme park', function () {
    $park = ThemePark::factory()->create();
    $admin = User::factory()->superadmin()->create();

    $this->actingAs($admin)
        ->putJson("/api/theme-parks/{$park->id}", ['name' => 'Admin Update'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Admin Update');
});

test('park-manager cannot delete a theme park', function () {
    $park = ThemePark::factory()->create();
    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->deleteJson("/api/theme-parks/{$park->id}")
        ->assertForbidden();
});

test('superadmin can archive a theme park (soft-delete)', function () {
    $park = ThemePark::factory()->create();
    $admin = User::factory()->superadmin()->create();

    $this->actingAs($admin)
        ->deleteJson("/api/theme-parks/{$park->id}")
        ->assertNoContent();

    expect($park->fresh()->trashed())->toBeTrue();
});

test('archived theme park is hidden from the public index', function () {
    $live = ThemePark::factory()->create(['name' => 'Live Park']);
    $archived = ThemePark::factory()->create(['name' => 'Archived Park']);
    $archived->delete();

    $response = getJson('/api/theme-parks')->assertOk();

    $names = collect($response->json('data'))->pluck('name')->all();
    expect($names)->toContain('Live Park')->not->toContain('Archived Park');
});

test('archive is blocked when park has upcoming confirmed park bookings', function () {
    $park = ThemePark::factory()->create();
    $customer = User::factory()->customer()->create();
    $reservation = \App\Models\Reservation::create(['user_id' => $customer->id]);
    \App\Models\ParkBooking::create([
        'reservation_id'  => $reservation->id,
        'park_id'         => $park->id,
        'date'            => now()->addDays(3)->toDateString(),
        'guests'          => 2,
        'status'          => 'confirmed',
        'price_per_guest' => $park->price,
        'total_price'     => (float) $park->price * 2,
    ]);

    $admin = User::factory()->superadmin()->create();

    $this->actingAs($admin)
        ->deleteJson("/api/theme-parks/{$park->id}")
        ->assertStatus(409)
        ->assertJsonPath('blocking_bookings', 1);

    expect($park->fresh()->trashed())->toBeFalse();
});

test('archive is blocked when park has upcoming activity bookings on nested schedules', function () {
    $park = ThemePark::factory()->create();
    $activity = \App\Models\ParkActivity::factory()->create(['park_id' => $park->id]);
    $schedule = $activity->schedules()->create([
        'date'       => now()->addDays(2)->toDateString(),
        'start_time' => '10:00:00',
        'end_time'   => '11:00:00',
        'status'     => \App\Models\ParkActivitySchedule::STATUS_SCHEDULED,
    ]);

    $customer = User::factory()->customer()->create();
    $reservation = \App\Models\Reservation::create(['user_id' => $customer->id]);
    \App\Models\ParkActivityBooking::create([
        'reservation_id'            => $reservation->id,
        'park_activity_schedule_id' => $schedule->id,
        'guests'                    => 1,
        'status'                    => 'confirmed',
        'price_per_guest'           => $activity->price,
        'total_price'               => (float) $activity->price,
    ]);

    $admin = User::factory()->superadmin()->create();

    $this->actingAs($admin)
        ->deleteJson("/api/theme-parks/{$park->id}")
        ->assertStatus(409)
        ->assertJsonPath('blocking_bookings', 1);
});

test('superadmin can restore an archived theme park and children cascade back', function () {
    $park = ThemePark::factory()->create();
    $activity = \App\Models\ParkActivity::factory()->create(['park_id' => $park->id]);
    $schedule = $activity->schedules()->create([
        'date'       => now()->addDays(30)->toDateString(),
        'start_time' => '09:00:00',
        'end_time'   => '10:00:00',
        'status'     => \App\Models\ParkActivitySchedule::STATUS_SCHEDULED,
    ]);

    $park->delete();

    expect($park->fresh()->trashed())->toBeTrue();
    expect($activity->fresh()->trashed())->toBeTrue();
    expect($schedule->fresh()->trashed())->toBeTrue();

    $admin = User::factory()->superadmin()->create();

    $this->actingAs($admin)
        ->postJson("/api/theme-parks/{$park->id}/restore")
        ->assertOk()
        ->assertJsonPath('data.id', $park->id);

    expect($park->fresh()->trashed())->toBeFalse();
    expect($activity->fresh()->trashed())->toBeFalse();
    expect($schedule->fresh()->trashed())->toBeFalse();
});

test('non-superadmin cannot restore a theme park', function () {
    $park = ThemePark::factory()->create();
    $park->delete();

    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->postJson("/api/theme-parks/{$park->id}/restore")
        ->assertForbidden();
});
