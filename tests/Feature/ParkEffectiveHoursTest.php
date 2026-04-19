<?php

use App\Models\ThemePark;

use function Pest\Laravel\getJson;

test('returns baseline hours when no override exists', function () {
    $park = ThemePark::factory()->create();
    $park->openingHours()->create([
        'day' => 'monday',
        'open_time' => '09:00:00',
        'close_time' => '17:00:00',
    ]);

    getJson("/api/theme-parks/{$park->id}/effective-hours?date=2026-06-01")
        ->assertOk()
        ->assertJsonPath('data.0.date', '2026-06-01')
        ->assertJsonPath('data.0.status', 'open')
        ->assertJsonPath('data.0.source', 'baseline')
        ->assertJsonPath('data.0.open_time', '09:00:00')
        ->assertJsonPath('data.0.close_time', '17:00:00')
        ->assertJsonPath('data.0.note', null);
});

test('override beats baseline on the same date', function () {
    $park = ThemePark::factory()->create();
    $park->openingHours()->create([
        'day' => 'saturday',
        'open_time' => '08:00:00',
        'close_time' => '20:00:00',
    ]);
    $park->hourOverrides()->create([
        'date' => '2026-07-04',
        'open_time' => '10:00:00',
        'close_time' => '14:00:00',
        'note' => 'Holiday hours',
    ]);

    getJson("/api/theme-parks/{$park->id}/effective-hours?date=2026-07-04")
        ->assertOk()
        ->assertJsonPath('data.0.source', 'override')
        ->assertJsonPath('data.0.open_time', '10:00:00')
        ->assertJsonPath('data.0.close_time', '14:00:00')
        ->assertJsonPath('data.0.note', 'Holiday hours');
});

test('closed-day override reports status closed', function () {
    $park = ThemePark::factory()->create();
    $park->openingHours()->create([
        'day' => 'friday',
        'open_time' => '09:00:00',
        'close_time' => '17:00:00',
    ]);
    $park->hourOverrides()->create([
        'date' => '2026-12-25',
        'open_time' => null,
        'close_time' => null,
        'note' => 'Christmas',
    ]);

    getJson("/api/theme-parks/{$park->id}/effective-hours?date=2026-12-25")
        ->assertOk()
        ->assertJsonPath('data.0.status', 'closed')
        ->assertJsonPath('data.0.source', 'override')
        ->assertJsonPath('data.0.open_time', null)
        ->assertJsonPath('data.0.close_time', null)
        ->assertJsonPath('data.0.note', 'Christmas');
});

test('returns not_configured for a date with no baseline or override', function () {
    $park = ThemePark::factory()->create();

    getJson("/api/theme-parks/{$park->id}/effective-hours?date=2026-06-01")
        ->assertOk()
        ->assertJsonPath('data.0.status', 'not_configured')
        ->assertJsonPath('data.0.source', null)
        ->assertJsonPath('data.0.open_time', null)
        ->assertJsonPath('data.0.close_time', null);
});

test('range query returns a dense array with one entry per date', function () {
    $park = ThemePark::factory()->create();
    $park->openingHours()->create([
        'day' => 'monday',
        'open_time' => '09:00:00',
        'close_time' => '17:00:00',
    ]);
    $park->hourOverrides()->create([
        'date' => '2026-06-02',
        'open_time' => null,
        'close_time' => null,
        'note' => 'Closed for maintenance',
    ]);

    $response = getJson("/api/theme-parks/{$park->id}/effective-hours?from=2026-06-01&to=2026-06-03")
        ->assertOk();

    expect($response->json('data'))->toHaveCount(3);

    $response
        ->assertJsonPath('data.0.date', '2026-06-01')
        ->assertJsonPath('data.0.source', 'baseline')
        ->assertJsonPath('data.0.status', 'open')
        ->assertJsonPath('data.1.date', '2026-06-02')
        ->assertJsonPath('data.1.source', 'override')
        ->assertJsonPath('data.1.status', 'closed')
        ->assertJsonPath('data.2.date', '2026-06-03')
        ->assertJsonPath('data.2.source', null)
        ->assertJsonPath('data.2.status', 'not_configured');
});

test('range query single-day returns a one-element array', function () {
    $park = ThemePark::factory()->create();

    $response = getJson("/api/theme-parks/{$park->id}/effective-hours?from=2026-06-01&to=2026-06-01")
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1);
});

test('public can call effective-hours without auth', function () {
    $park = ThemePark::factory()->create();

    getJson("/api/theme-parks/{$park->id}/effective-hours?date=2026-06-01")->assertOk();
});

test('missing both date and range fails validation', function () {
    $park = ThemePark::factory()->create();

    getJson("/api/theme-parks/{$park->id}/effective-hours")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('date');
});

test('to before from fails validation', function () {
    $park = ThemePark::factory()->create();

    getJson("/api/theme-parks/{$park->id}/effective-hours?from=2026-06-05&to=2026-06-01")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('to');
});

test('from without to fails validation', function () {
    $park = ThemePark::factory()->create();

    getJson("/api/theme-parks/{$park->id}/effective-hours?from=2026-06-01")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('to');
});

test('range beyond 366 days fails validation', function () {
    $park = ThemePark::factory()->create();

    getJson("/api/theme-parks/{$park->id}/effective-hours?from=2026-01-01&to=2027-06-01")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('to');
});
