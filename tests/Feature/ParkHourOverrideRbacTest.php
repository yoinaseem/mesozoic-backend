<?php

use App\Models\ThemePark;
use App\Models\User;

use function Pest\Laravel\getJson;

test('public can list park hour overrides without auth', function () {
    $park = ThemePark::factory()->create();
    $park->hourOverrides()->create([
        'date' => '2026-07-04',
        'open_time' => '10:00:00',
        'close_time' => '14:00:00',
        'note' => 'Independence Day — short hours',
    ]);

    getJson("/api/theme-parks/{$park->id}/hour-overrides")->assertOk();
});

test('public can view a single park hour override without auth', function () {
    $park = ThemePark::factory()->create();
    $override = $park->hourOverrides()->create([
        'date' => '2026-12-25',
        'open_time' => null,
        'close_time' => null,
        'note' => 'Christmas Day — closed',
    ]);

    getJson("/api/theme-parks/{$park->id}/hour-overrides/{$override->id}")
        ->assertOk()
        ->assertJsonPath('data.is_closed', true);
});

test('unauthenticated user cannot create a park hour override', function () {
    $park = ThemePark::factory()->create();

    $this->postJson("/api/theme-parks/{$park->id}/hour-overrides", [
        'date' => '2026-07-04',
        'open_time' => '10:00:00',
        'close_time' => '14:00:00',
    ])->assertUnauthorized();
});

test('customer cannot create a park hour override', function () {
    $park = ThemePark::factory()->create();
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)
        ->postJson("/api/theme-parks/{$park->id}/hour-overrides", [
            'date' => '2026-07-04',
            'open_time' => '10:00:00',
            'close_time' => '14:00:00',
        ])
        ->assertForbidden();
});

test('park-manager can create a park hour override with hours', function () {
    $park = ThemePark::factory()->create();
    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->postJson("/api/theme-parks/{$park->id}/hour-overrides", [
            'date' => '2026-07-04',
            'open_time' => '10:00:00',
            'close_time' => '14:00:00',
            'note' => 'Holiday hours',
        ])
        ->assertCreated()
        ->assertJsonPath('data.date', '2026-07-04')
        ->assertJsonPath('data.is_closed', false);
});

test('park-manager can create a closed-day override (both times null)', function () {
    $park = ThemePark::factory()->create();
    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->postJson("/api/theme-parks/{$park->id}/hour-overrides", [
            'date' => '2026-12-25',
            'note' => 'Christmas Day — closed',
        ])
        ->assertCreated()
        ->assertJsonPath('data.is_closed', true)
        ->assertJsonPath('data.open_time', null)
        ->assertJsonPath('data.close_time', null);
});

test('park-manager can create an overnight override where close_time is earlier than open_time', function () {
    $park = ThemePark::factory()->create();
    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->postJson("/api/theme-parks/{$park->id}/hour-overrides", [
            'date' => '2026-10-31',
            'open_time' => '18:00:00',
            'close_time' => '02:00:00',
            'note' => 'Halloween late hours',
        ])
        ->assertCreated()
        ->assertJsonPath('data.open_time', '18:00:00')
        ->assertJsonPath('data.close_time', '02:00:00');
});

test('override rejects open_time equal to close_time', function () {
    $park = ThemePark::factory()->create();
    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->postJson("/api/theme-parks/{$park->id}/hour-overrides", [
            'date' => '2026-07-04',
            'open_time' => '10:00:00',
            'close_time' => '10:00:00',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('close_time');
});

test('override rejects only one of open_time or close_time provided', function () {
    $park = ThemePark::factory()->create();
    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->postJson("/api/theme-parks/{$park->id}/hour-overrides", [
            'date' => '2026-07-04',
            'open_time' => '10:00:00',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('close_time');
});

test('override rejects duplicate date for the same park', function () {
    $park = ThemePark::factory()->create();
    $park->hourOverrides()->create([
        'date' => '2026-07-04',
        'open_time' => '10:00:00',
        'close_time' => '14:00:00',
    ]);
    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->postJson("/api/theme-parks/{$park->id}/hour-overrides", [
            'date' => '2026-07-04',
            'open_time' => '12:00:00',
            'close_time' => '20:00:00',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('date');
});

test('park-manager can update a park hour override', function () {
    $park = ThemePark::factory()->create();
    $override = $park->hourOverrides()->create([
        'date' => '2026-07-04',
        'open_time' => '10:00:00',
        'close_time' => '14:00:00',
    ]);
    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->patchJson("/api/theme-parks/{$park->id}/hour-overrides/{$override->id}", [
            'close_time' => '18:00:00',
        ])
        ->assertOk()
        ->assertJsonPath('data.close_time', '18:00:00');
});

test('updating an override to close the day nulls both times', function () {
    $park = ThemePark::factory()->create();
    $override = $park->hourOverrides()->create([
        'date' => '2026-07-04',
        'open_time' => '10:00:00',
        'close_time' => '14:00:00',
    ]);
    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->patchJson("/api/theme-parks/{$park->id}/hour-overrides/{$override->id}", [
            'open_time' => null,
            'close_time' => null,
            'note' => 'Closed due to weather',
        ])
        ->assertOk()
        ->assertJsonPath('data.is_closed', true);
});

test('update rejects nulling only one of open_time or close_time', function () {
    $park = ThemePark::factory()->create();
    $override = $park->hourOverrides()->create([
        'date' => '2026-07-04',
        'open_time' => '10:00:00',
        'close_time' => '14:00:00',
    ]);
    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->patchJson("/api/theme-parks/{$park->id}/hour-overrides/{$override->id}", [
            'close_time' => null,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('open_time');
});

test('update rejects setting open_time equal to close_time', function () {
    $park = ThemePark::factory()->create();
    $override = $park->hourOverrides()->create([
        'date' => '2026-07-04',
        'open_time' => '10:00:00',
        'close_time' => '14:00:00',
    ]);
    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->patchJson("/api/theme-parks/{$park->id}/hour-overrides/{$override->id}", [
            'close_time' => '10:00:00',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('close_time');
});

test('customer cannot update a park hour override', function () {
    $park = ThemePark::factory()->create();
    $override = $park->hourOverrides()->create([
        'date' => '2026-07-04',
        'open_time' => '10:00:00',
        'close_time' => '14:00:00',
    ]);
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)
        ->patchJson("/api/theme-parks/{$park->id}/hour-overrides/{$override->id}", [
            'close_time' => '18:00:00',
        ])
        ->assertForbidden();
});

test('park-manager cannot delete a park hour override', function () {
    $park = ThemePark::factory()->create();
    $override = $park->hourOverrides()->create([
        'date' => '2026-07-04',
        'open_time' => '10:00:00',
        'close_time' => '14:00:00',
    ]);
    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->deleteJson("/api/theme-parks/{$park->id}/hour-overrides/{$override->id}")
        ->assertForbidden();
});

test('superadmin can delete a park hour override', function () {
    $park = ThemePark::factory()->create();
    $override = $park->hourOverrides()->create([
        'date' => '2026-07-04',
        'open_time' => '10:00:00',
        'close_time' => '14:00:00',
    ]);
    $admin = User::factory()->superadmin()->create();

    $this->actingAs($admin)
        ->deleteJson("/api/theme-parks/{$park->id}/hour-overrides/{$override->id}")
        ->assertNoContent();
});
