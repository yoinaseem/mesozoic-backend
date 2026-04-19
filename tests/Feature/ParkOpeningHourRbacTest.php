<?php

use App\Models\ParkOpeningHour;
use App\Models\ThemePark;
use App\Models\User;

use function Pest\Laravel\getJson;

test('public can list park opening hours without auth', function () {
    $park = ThemePark::factory()->create();
    $park->openingHours()->create([
        'day' => 'monday',
        'open_time' => '09:00:00',
        'close_time' => '17:00:00',
    ]);

    getJson("/api/theme-parks/{$park->id}/opening-hours")->assertOk();
});

test('public can view a single park opening hour without auth', function () {
    $park = ThemePark::factory()->create();
    $hour = $park->openingHours()->create([
        'day' => 'monday',
        'open_time' => '09:00:00',
        'close_time' => '17:00:00',
    ]);

    getJson("/api/theme-parks/{$park->id}/opening-hours/{$hour->id}")->assertOk();
});

test('unauthenticated user cannot create a park opening hour', function () {
    $park = ThemePark::factory()->create();

    $this->postJson("/api/theme-parks/{$park->id}/opening-hours", [
        'day' => 'monday',
        'open_time' => '09:00:00',
        'close_time' => '17:00:00',
    ])->assertUnauthorized();
});

test('customer cannot create a park opening hour', function () {
    $park = ThemePark::factory()->create();
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)
        ->postJson("/api/theme-parks/{$park->id}/opening-hours", [
            'day' => 'monday',
            'open_time' => '09:00:00',
            'close_time' => '17:00:00',
        ])
        ->assertForbidden();
});

test('park-manager can create a park opening hour', function () {
    $park = ThemePark::factory()->create();
    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->postJson("/api/theme-parks/{$park->id}/opening-hours", [
            'day' => 'monday',
            'open_time' => '09:00:00',
            'close_time' => '17:00:00',
        ])
        ->assertCreated()
        ->assertJsonPath('data.day', 'monday');
});

test('superadmin can create a park opening hour', function () {
    $park = ThemePark::factory()->create();
    $admin = User::factory()->superadmin()->create();

    $this->actingAs($admin)
        ->postJson("/api/theme-parks/{$park->id}/opening-hours", [
            'day' => 'tuesday',
            'open_time' => '10:00:00',
            'close_time' => '20:00:00',
        ])
        ->assertCreated()
        ->assertJsonPath('data.day', 'tuesday');
});

test('park-manager can create overnight opening hours where close_time is earlier than open_time', function () {
    $park = ThemePark::factory()->create();
    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->postJson("/api/theme-parks/{$park->id}/opening-hours", [
            'day' => 'friday',
            'open_time' => '18:00:00',
            'close_time' => '02:00:00',
        ])
        ->assertCreated()
        ->assertJsonPath('data.open_time', '18:00:00')
        ->assertJsonPath('data.close_time', '02:00:00');
});

test('park-manager cannot create opening hours with an invalid day', function () {
    $park = ThemePark::factory()->create();
    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->postJson("/api/theme-parks/{$park->id}/opening-hours", [
            'day' => 'moonday',
            'open_time' => '09:00:00',
            'close_time' => '17:00:00',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('day');
});

test('park-manager cannot create opening hours where open_time equals close_time', function () {
    $park = ThemePark::factory()->create();
    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->postJson("/api/theme-parks/{$park->id}/opening-hours", [
            'day' => 'monday',
            'open_time' => '09:00:00',
            'close_time' => '09:00:00',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('close_time');
});

test('park-manager can update a park opening hour', function () {
    $park = ThemePark::factory()->create();
    $hour = $park->openingHours()->create([
        'day' => 'monday',
        'open_time' => '09:00:00',
        'close_time' => '17:00:00',
    ]);
    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->patchJson("/api/theme-parks/{$park->id}/opening-hours/{$hour->id}", [
            'close_time' => '18:00:00',
        ])
        ->assertOk()
        ->assertJsonPath('data.close_time', '18:00:00');
});

test('park-manager can update opening hour to an overnight window', function () {
    $park = ThemePark::factory()->create();
    $hour = $park->openingHours()->create([
        'day' => 'saturday',
        'open_time' => '09:00:00',
        'close_time' => '17:00:00',
    ]);
    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->patchJson("/api/theme-parks/{$park->id}/opening-hours/{$hour->id}", [
            'open_time' => '20:00:00',
            'close_time' => '03:00:00',
        ])
        ->assertOk()
        ->assertJsonPath('data.open_time', '20:00:00')
        ->assertJsonPath('data.close_time', '03:00:00');
});

test('park-manager cannot update opening hour so open_time equals close_time', function () {
    $park = ThemePark::factory()->create();
    $hour = $park->openingHours()->create([
        'day' => 'monday',
        'open_time' => '09:00:00',
        'close_time' => '17:00:00',
    ]);
    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->patchJson("/api/theme-parks/{$park->id}/opening-hours/{$hour->id}", [
            'close_time' => '09:00:00',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('close_time');
});

test('customer cannot update a park opening hour', function () {
    $park = ThemePark::factory()->create();
    $hour = $park->openingHours()->create([
        'day' => 'monday',
        'open_time' => '09:00:00',
        'close_time' => '17:00:00',
    ]);
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)
        ->patchJson("/api/theme-parks/{$park->id}/opening-hours/{$hour->id}", [
            'close_time' => '18:00:00',
        ])
        ->assertForbidden();
});

test('superadmin can update any park opening hour', function () {
    $park = ThemePark::factory()->create();
    $hour = $park->openingHours()->create([
        'day' => 'monday',
        'open_time' => '09:00:00',
        'close_time' => '17:00:00',
    ]);
    $admin = User::factory()->superadmin()->create();

    $this->actingAs($admin)
        ->patchJson("/api/theme-parks/{$park->id}/opening-hours/{$hour->id}", [
            'close_time' => '22:00:00',
        ])
        ->assertOk()
        ->assertJsonPath('data.close_time', '22:00:00');
});

test('park-manager cannot delete a park opening hour', function () {
    $park = ThemePark::factory()->create();
    $hour = $park->openingHours()->create([
        'day' => 'monday',
        'open_time' => '09:00:00',
        'close_time' => '17:00:00',
    ]);
    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->deleteJson("/api/theme-parks/{$park->id}/opening-hours/{$hour->id}")
        ->assertForbidden();
});

test('superadmin can delete a park opening hour', function () {
    $park = ThemePark::factory()->create();
    $hour = $park->openingHours()->create([
        'day' => 'monday',
        'open_time' => '09:00:00',
        'close_time' => '17:00:00',
    ]);
    $admin = User::factory()->superadmin()->create();

    $this->actingAs($admin)
        ->deleteJson("/api/theme-parks/{$park->id}/opening-hours/{$hour->id}")
        ->assertNoContent();
});
