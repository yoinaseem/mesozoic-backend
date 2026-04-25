<?php

use App\Models\ParkActivity;
use App\Models\ParkActivityBooking;
use App\Models\ParkActivitySchedule;
use App\Models\Reservation;
use App\Models\ThemePark;
use App\Models\User;

function overrideCascadePark(string $open = '09:00:00', string $close = '17:00:00'): ThemePark
{
    $park = ThemePark::factory()->create();
    foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
        $park->openingHours()->create([
            'day' => $day,
            'open_time' => $open,
            'close_time' => $close,
        ]);
    }

    return $park;
}

function makeBookedSchedule(ThemePark $park, string $date, string $start, string $end): array
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

test('override with no schedule conflicts is created normally', function () {
    $park = overrideCascadePark();
    $manager = User::factory()->parkManager()->create();
    $date = now()->addDays(5)->toDateString();

    $this->actingAs($manager)
        ->postJson("/api/theme-parks/{$park->id}/hour-overrides", [
            'date' => $date,
            'open_time' => '11:00:00',
            'close_time' => '14:00:00',
        ])
        ->assertCreated()
        ->assertJsonPath('data.open_time', '11:00:00')
        ->assertJsonPath('cascade.schedules_cancelled', 0)
        ->assertJsonPath('cascade.bookings_cancelled', 0);
});

test('override that invalidates a schedule is rejected with 409 by default', function () {
    $park = overrideCascadePark();
    $date = now()->addDays(5)->toDateString();
    [$activity, $schedule, $booking] = makeBookedSchedule($park, $date, '09:30:00', '10:30:00');

    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->postJson("/api/theme-parks/{$park->id}/hour-overrides", [
            'date' => $date,
            'open_time' => '11:00:00',
            'close_time' => '14:00:00',
        ])
        ->assertStatus(409)
        ->assertJsonPath('counts.schedules', 1)
        ->assertJsonPath('counts.bookings', 1)
        ->assertJsonPath('conflicts.0.schedule_id', $schedule->id)
        ->assertJsonPath('conflicts.0.confirmed_bookings', 1);

    expect($schedule->fresh()->status)->toBe(ParkActivitySchedule::STATUS_SCHEDULED);
    expect($booking->fresh()->status)->toBe('confirmed');
    expect($park->hourOverrides()->count())->toBe(0);
});

test('override with on_conflict=cascade cancels affected schedules and bookings', function () {
    $park = overrideCascadePark();
    $date = now()->addDays(5)->toDateString();
    [$activity, $schedule, $booking] = makeBookedSchedule($park, $date, '09:30:00', '10:30:00');

    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->postJson("/api/theme-parks/{$park->id}/hour-overrides", [
            'date' => $date,
            'open_time' => '11:00:00',
            'close_time' => '14:00:00',
            'on_conflict' => 'cascade',
        ])
        ->assertCreated()
        ->assertJsonPath('cascade.schedules_cancelled', 1)
        ->assertJsonPath('cascade.bookings_cancelled', 1);

    expect($schedule->fresh()->status)->toBe(ParkActivitySchedule::STATUS_CANCELLED);
    expect($schedule->fresh()->notes)->toContain('[cascade]');
    expect($booking->fresh()->status)->toBe('cancelled');
    expect($booking->fresh()->cancelled_at)->not->toBeNull();
    expect($park->hourOverrides()->count())->toBe(1);
});

test('closed-day override with cascade cancels all schedules on that date', function () {
    $park = overrideCascadePark();
    $date = now()->addDays(5)->toDateString();
    [, $scheduleA] = makeBookedSchedule($park, $date, '10:00:00', '11:00:00');
    [, $scheduleB] = makeBookedSchedule($park, $date, '13:00:00', '14:00:00');

    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->postJson("/api/theme-parks/{$park->id}/hour-overrides", [
            'date' => $date,
            'open_time' => null,
            'close_time' => null,
            'on_conflict' => 'cascade',
        ])
        ->assertCreated()
        ->assertJsonPath('cascade.schedules_cancelled', 2);

    expect($scheduleA->fresh()->status)->toBe(ParkActivitySchedule::STATUS_CANCELLED);
    expect($scheduleB->fresh()->status)->toBe(ParkActivitySchedule::STATUS_CANCELLED);
});

test('deleting an override never invalidates schedules and returns 204', function () {
    $park = overrideCascadePark();
    $date = now()->addDays(5)->toDateString();
    $override = $park->hourOverrides()->create([
        'date' => $date,
        'open_time' => '11:00:00',
        'close_time' => '14:00:00',
    ]);
    [, $schedule] = makeBookedSchedule($park, $date, '12:00:00', '13:00:00');

    $admin = User::factory()->superadmin()->create();

    $this->actingAs($admin)
        ->deleteJson("/api/theme-parks/{$park->id}/hour-overrides/{$override->id}")
        ->assertNoContent();

    expect($schedule->fresh()->status)->toBe(ParkActivitySchedule::STATUS_SCHEDULED);
});

test('updating an override to narrow hours follows the same hybrid path', function () {
    $park = overrideCascadePark();
    $date = now()->addDays(5)->toDateString();
    $override = $park->hourOverrides()->create([
        'date' => $date,
        'open_time' => '09:00:00',
        'close_time' => '17:00:00',
    ]);
    [, $schedule, $booking] = makeBookedSchedule($park, $date, '09:30:00', '10:30:00');

    $manager = User::factory()->parkManager()->create();

    // Reject by default
    $this->actingAs($manager)
        ->patchJson("/api/theme-parks/{$park->id}/hour-overrides/{$override->id}", [
            'open_time' => '11:00:00',
            'close_time' => '14:00:00',
        ])
        ->assertStatus(409);

    expect($override->fresh()->open_time)->toBe('09:00:00');

    // Cascade on confirm
    $this->actingAs($manager)
        ->patchJson("/api/theme-parks/{$park->id}/hour-overrides/{$override->id}", [
            'open_time' => '11:00:00',
            'close_time' => '14:00:00',
            'on_conflict' => 'cascade',
        ])
        ->assertOk()
        ->assertJsonPath('cascade.schedules_cancelled', 1)
        ->assertJsonPath('cascade.bookings_cancelled', 1);

    expect($override->fresh()->open_time)->toBe('11:00:00');
    expect($schedule->fresh()->status)->toBe(ParkActivitySchedule::STATUS_CANCELLED);
    expect($booking->fresh()->status)->toBe('cancelled');
});

test('deleting a broader override that narrows hours back to baseline is rejected by default', function () {
    // Baseline 09:00–17:00. Override widens to 06:00–22:00 for an event day.
    // A schedule was authored at 06:30–07:30, valid under the override.
    // Deleting the override snaps hours back to baseline → 06:30 schedule
    // now sits outside the open window, must surface as a 409 conflict.
    $park = overrideCascadePark('09:00:00', '17:00:00');
    $date = now()->addDays(5)->toDateString();

    $override = $park->hourOverrides()->create([
        'date' => $date,
        'open_time' => '06:00:00',
        'close_time' => '22:00:00',
    ]);
    [$activity, $schedule, $booking] = makeBookedSchedule($park, $date, '06:30:00', '07:30:00');

    $admin = User::factory()->superadmin()->create();

    $this->actingAs($admin)
        ->deleteJson("/api/theme-parks/{$park->id}/hour-overrides/{$override->id}")
        ->assertStatus(409)
        ->assertJsonPath('counts.schedules', 1)
        ->assertJsonPath('counts.bookings', 1)
        ->assertJsonPath('conflicts.0.schedule_id', $schedule->id);

    expect($override->fresh())->not->toBeNull();
    expect($schedule->fresh()->status)->toBe(ParkActivitySchedule::STATUS_SCHEDULED);
    expect($booking->fresh()->status)->toBe('confirmed');
});

test('deleting a broader override with on_conflict=cascade cancels invalidated schedules', function () {
    $park = overrideCascadePark('09:00:00', '17:00:00');
    $date = now()->addDays(5)->toDateString();

    $override = $park->hourOverrides()->create([
        'date' => $date,
        'open_time' => '06:00:00',
        'close_time' => '22:00:00',
    ]);
    [$activity, $schedule, $booking] = makeBookedSchedule($park, $date, '06:30:00', '07:30:00');

    $admin = User::factory()->superadmin()->create();

    $this->actingAs($admin)
        ->deleteJson("/api/theme-parks/{$park->id}/hour-overrides/{$override->id}?on_conflict=cascade")
        ->assertOk()
        ->assertJsonPath('cascade.schedules_cancelled', 1)
        ->assertJsonPath('cascade.bookings_cancelled', 1);

    expect($park->hourOverrides()->count())->toBe(0);
    expect($schedule->fresh()->status)->toBe(ParkActivitySchedule::STATUS_CANCELLED);
    expect($schedule->fresh()->notes)->toContain('[cascade]');
    expect($booking->fresh()->status)->toBe('cancelled');
});

test('deleting an override on a park with no baseline rejects when the date had live schedules', function () {
    // No baseline configured. The override is the only thing keeping the
    // date open — deleting it makes the day not_configured (fail-closed),
    // so any live schedule on that date is now invalid.
    $park = ThemePark::factory()->create();

    $date = now()->addDays(5)->toDateString();
    $override = $park->hourOverrides()->create([
        'date' => $date,
        'open_time' => '09:00:00',
        'close_time' => '17:00:00',
    ]);
    [$activity, $schedule, $booking] = makeBookedSchedule($park, $date, '10:00:00', '11:00:00');

    $admin = User::factory()->superadmin()->create();

    $this->actingAs($admin)
        ->deleteJson("/api/theme-parks/{$park->id}/hour-overrides/{$override->id}")
        ->assertStatus(409)
        ->assertJsonPath('counts.schedules', 1);

    expect($override->fresh())->not->toBeNull();
    expect($schedule->fresh()->status)->toBe(ParkActivitySchedule::STATUS_SCHEDULED);
});
