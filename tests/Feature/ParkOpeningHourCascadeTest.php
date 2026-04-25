<?php

use App\Models\ParkActivity;
use App\Models\ParkActivityBooking;
use App\Models\ParkActivitySchedule;
use App\Models\Reservation;
use App\Models\ThemePark;
use App\Models\User;

function makeOpeningHourSchedule(ThemePark $park, string $date, string $start, string $end): array
{
    $activity = ParkActivity::factory()->create([
        'park_id' => $park->id,
        'price' => 30,
        'max_capacity' => 10,
    ]);
    $schedule = $activity->schedules()->create([
        'date' => $date,
        'start_time' => $start,
        'end_time' => $end,
        'status' => ParkActivitySchedule::STATUS_SCHEDULED,
    ]);
    $customer = User::factory()->customer()->create();
    $reservation = Reservation::create(['user_id' => $customer->id]);
    $booking = ParkActivityBooking::create([
        'reservation_id' => $reservation->id,
        'park_activity_schedule_id' => $schedule->id,
        'guests' => 2,
        'status' => 'confirmed',
        'price_per_guest' => 30,
        'total_price' => 60,
    ]);

    return [$activity, $schedule, $booking];
}

/**
 * Pick a future date that lands on a specific weekday so we can hang a
 * baseline-edit conflict on it. Returns the next occurrence of $weekday at
 * least $minDaysOut days from today.
 */
function nextWeekdayOnOrAfter(string $weekday, int $minDaysOut = 1): string
{
    $target = strtolower($weekday);
    $cursor = now()->addDays($minDaysOut);
    while (strtolower($cursor->format('l')) !== $target) {
        $cursor = $cursor->addDay();
    }

    return $cursor->toDateString();
}

test('opening hour create with no schedule conflicts succeeds normally', function () {
    $park = ThemePark::factory()->create();
    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->postJson("/api/theme-parks/{$park->id}/opening-hours", [
            'day' => 'monday',
            'open_time' => '09:00:00',
            'close_time' => '17:00:00',
        ])
        ->assertCreated()
        ->assertJsonPath('cascade.schedules_cancelled', 0);
});

test('narrowing a baseline that invalidates a schedule is rejected with 409 by default', function () {
    $park = ThemePark::factory()->create();
    $park->openingHours()->create([
        'day' => 'monday',
        'open_time' => '09:00:00',
        'close_time' => '17:00:00',
    ]);
    foreach (['tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
        $park->openingHours()->create([
            'day' => $day,
            'open_time' => '09:00:00',
            'close_time' => '17:00:00',
        ]);
    }
    $monday = nextWeekdayOnOrAfter('monday');
    [, $schedule, $booking] = makeOpeningHourSchedule($park, $monday, '09:30:00', '10:30:00');

    $hour = $park->openingHours()->where('day', 'monday')->first();
    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->patchJson("/api/theme-parks/{$park->id}/opening-hours/{$hour->id}", [
            'open_time' => '11:00:00',
            'close_time' => '17:00:00',
        ])
        ->assertStatus(409)
        ->assertJsonPath('counts.schedules', 1)
        ->assertJsonPath('counts.bookings', 1);

    expect($hour->fresh()->open_time)->toBe('09:00:00');
    expect($schedule->fresh()->status)->toBe(ParkActivitySchedule::STATUS_SCHEDULED);
    expect($booking->fresh()->status)->toBe('confirmed');
});

test('narrowing a baseline with on_conflict=cascade cancels affected schedules and bookings', function () {
    $park = ThemePark::factory()->create();
    foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
        $park->openingHours()->create([
            'day' => $day,
            'open_time' => '09:00:00',
            'close_time' => '17:00:00',
        ]);
    }
    $monday = nextWeekdayOnOrAfter('monday');
    [, $schedule, $booking] = makeOpeningHourSchedule($park, $monday, '09:30:00', '10:30:00');

    $hour = $park->openingHours()->where('day', 'monday')->first();
    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->patchJson("/api/theme-parks/{$park->id}/opening-hours/{$hour->id}", [
            'open_time' => '11:00:00',
            'close_time' => '17:00:00',
            'on_conflict' => 'cascade',
        ])
        ->assertOk()
        ->assertJsonPath('cascade.schedules_cancelled', 1)
        ->assertJsonPath('cascade.bookings_cancelled', 1);

    expect($hour->fresh()->open_time)->toBe('11:00:00');
    expect($schedule->fresh()->status)->toBe(ParkActivitySchedule::STATUS_CANCELLED);
    expect($booking->fresh()->status)->toBe('cancelled');
});

test('deleting a baseline that strands a schedule is rejected with 409 by default', function () {
    $park = ThemePark::factory()->create();
    foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
        $park->openingHours()->create([
            'day' => $day,
            'open_time' => '09:00:00',
            'close_time' => '17:00:00',
        ]);
    }
    $monday = nextWeekdayOnOrAfter('monday');
    [, $schedule] = makeOpeningHourSchedule($park, $monday, '10:00:00', '11:00:00');

    $hour = $park->openingHours()->where('day', 'monday')->first();
    $admin = User::factory()->superadmin()->create();

    $this->actingAs($admin)
        ->deleteJson("/api/theme-parks/{$park->id}/opening-hours/{$hour->id}")
        ->assertStatus(409);

    expect($park->openingHours()->where('day', 'monday')->exists())->toBeTrue();
    expect($schedule->fresh()->status)->toBe(ParkActivitySchedule::STATUS_SCHEDULED);
});

test('deleting a baseline with on_conflict=cascade cancels stranded schedules', function () {
    $park = ThemePark::factory()->create();
    foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
        $park->openingHours()->create([
            'day' => $day,
            'open_time' => '09:00:00',
            'close_time' => '17:00:00',
        ]);
    }
    $monday = nextWeekdayOnOrAfter('monday');
    [, $schedule] = makeOpeningHourSchedule($park, $monday, '10:00:00', '11:00:00');

    $hour = $park->openingHours()->where('day', 'monday')->first();
    $admin = User::factory()->superadmin()->create();

    $this->actingAs($admin)
        ->deleteJson("/api/theme-parks/{$park->id}/opening-hours/{$hour->id}?on_conflict=cascade")
        ->assertOk()
        ->assertJsonPath('cascade.schedules_cancelled', 1);

    expect($park->openingHours()->where('day', 'monday')->exists())->toBeFalse();
    expect($schedule->fresh()->status)->toBe(ParkActivitySchedule::STATUS_CANCELLED);
});

test('deleting a baseline with no future schedules still returns 204', function () {
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
