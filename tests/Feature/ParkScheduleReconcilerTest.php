<?php

use App\Models\ParkActivity;
use App\Models\ParkActivityBooking;
use App\Models\ParkActivitySchedule;
use App\Models\Reservation;
use App\Models\ThemePark;
use App\Models\User;
use App\Services\ParkScheduleReconciler;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

function reconciler(): ParkScheduleReconciler
{
    return app(ParkScheduleReconciler::class);
}

function reconcilerPark(string $open = '09:00:00', string $close = '17:00:00'): ThemePark
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

// validateWindow

test('validateWindow accepts a schedule fully inside hours', function () {
    $park = reconcilerPark();
    reconciler()->validateWindow($park, '2026-06-01', '10:00:00', '11:00:00');
    expect(true)->toBeTrue();
});

test('validateWindow rejects a schedule starting before open', function () {
    $park = reconcilerPark();
    expect(fn () => reconciler()->validateWindow($park, '2026-06-01', '08:00:00', '10:00:00'))
        ->toThrow(ValidationException::class);
});

test('validateWindow rejects a schedule ending after close', function () {
    $park = reconcilerPark();
    expect(fn () => reconciler()->validateWindow($park, '2026-06-01', '16:00:00', '18:00:00'))
        ->toThrow(ValidationException::class);
});

test('validateWindow rejects on a closed-override date', function () {
    $park = reconcilerPark();
    $park->hourOverrides()->create([
        'date' => '2026-06-01',
        'open_time' => null,
        'close_time' => null,
    ]);
    expect(fn () => reconciler()->validateWindow($park, '2026-06-01', '10:00:00', '11:00:00'))
        ->toThrow(ValidationException::class);
});

test('validateWindow rejects on a not_configured date', function () {
    $park = ThemePark::factory()->create();
    expect(fn () => reconciler()->validateWindow($park, '2026-06-01', '10:00:00', '11:00:00'))
        ->toThrow(ValidationException::class);
});

test('validateWindow accepts an overnight schedule inside overnight hours', function () {
    $park = reconcilerPark('22:00:00', '02:00:00');
    reconciler()->validateWindow($park, '2026-06-01', '23:00:00', '01:00:00');
    expect(true)->toBeTrue();
});

test('validateWindow rejects an overnight schedule extending past overnight close', function () {
    $park = reconcilerPark('22:00:00', '02:00:00');
    expect(fn () => reconciler()->validateWindow($park, '2026-06-01', '23:00:00', '03:00:00'))
        ->toThrow(ValidationException::class);
});

// findOverlappingSchedule

test('findOverlappingSchedule returns null when there is no overlap', function () {
    $park = reconcilerPark();
    $activity = ParkActivity::factory()->create(['park_id' => $park->id]);
    $activity->schedules()->create([
        'date' => '2026-06-01',
        'start_time' => '10:00:00',
        'end_time' => '11:00:00',
    ]);

    $result = reconciler()->findOverlappingSchedule($activity, '2026-06-01', '11:00:00', '12:00:00');
    expect($result)->toBeNull();
});

test('findOverlappingSchedule detects partial overlap', function () {
    $park = reconcilerPark();
    $activity = ParkActivity::factory()->create(['park_id' => $park->id]);
    $existing = $activity->schedules()->create([
        'date' => '2026-06-01',
        'start_time' => '10:00:00',
        'end_time' => '11:00:00',
    ]);

    $result = reconciler()->findOverlappingSchedule($activity, '2026-06-01', '10:30:00', '11:30:00');
    expect($result?->id)->toBe($existing->id);
});

test('findOverlappingSchedule ignores cancelled schedules', function () {
    $park = reconcilerPark();
    $activity = ParkActivity::factory()->create(['park_id' => $park->id]);
    $activity->schedules()->create([
        'date' => '2026-06-01',
        'start_time' => '10:00:00',
        'end_time' => '11:00:00',
        'status' => ParkActivitySchedule::STATUS_CANCELLED,
    ]);

    $result = reconciler()->findOverlappingSchedule($activity, '2026-06-01', '10:30:00', '11:30:00');
    expect($result)->toBeNull();
});

test('findOverlappingSchedule ignores trashed schedules', function () {
    $park = reconcilerPark();
    $activity = ParkActivity::factory()->create(['park_id' => $park->id]);
    $existing = $activity->schedules()->create([
        'date' => '2026-06-01',
        'start_time' => '10:00:00',
        'end_time' => '11:00:00',
    ]);
    $existing->delete();

    $result = reconciler()->findOverlappingSchedule($activity, '2026-06-01', '10:30:00', '11:30:00');
    expect($result)->toBeNull();
});

test('findOverlappingSchedule respects ignoreId', function () {
    $park = reconcilerPark();
    $activity = ParkActivity::factory()->create(['park_id' => $park->id]);
    $existing = $activity->schedules()->create([
        'date' => '2026-06-01',
        'start_time' => '10:00:00',
        'end_time' => '11:00:00',
    ]);

    $result = reconciler()->findOverlappingSchedule(
        $activity,
        '2026-06-01',
        '10:30:00',
        '11:30:00',
        $existing->id,
    );
    expect($result)->toBeNull();
});

test('findOverlappingSchedule returns null for is_all_day activities', function () {
    $park = reconcilerPark();
    $activity = ParkActivity::factory()->create([
        'park_id' => $park->id,
        'is_all_day' => true,
        'duration' => null,
    ]);
    $activity->schedules()->create([
        'date' => '2026-06-01',
        'start_time' => '09:00:00',
        'end_time' => '17:00:00',
    ]);

    $result = reconciler()->findOverlappingSchedule($activity, '2026-06-01', '10:00:00', '11:00:00');
    expect($result)->toBeNull();
});

// findScheduleConflicts

test('findScheduleConflicts surfaces schedules whose windows broke', function () {
    $park = reconcilerPark();
    $activity = ParkActivity::factory()->create(['park_id' => $park->id]);
    $date = now()->addDays(5)->toDateString();
    $schedule = $activity->schedules()->create([
        'date' => $date,
        'start_time' => '09:00:00',
        'end_time' => '10:00:00',
    ]);
    $park->hourOverrides()->create([
        'date' => $date,
        'open_time' => '11:00:00',
        'close_time' => '17:00:00',
    ]);

    $conflicts = reconciler()->findScheduleConflicts(
        $park,
        CarbonImmutable::parse($date),
        CarbonImmutable::parse($date),
    );

    expect($conflicts)->toHaveCount(1);
    expect($conflicts->first()['schedule']->id)->toBe($schedule->id);
});

test('findScheduleConflicts skips schedules that still fit', function () {
    $park = reconcilerPark();
    $activity = ParkActivity::factory()->create(['park_id' => $park->id]);
    $date = now()->addDays(5)->toDateString();
    $activity->schedules()->create([
        'date' => $date,
        'start_time' => '12:00:00',
        'end_time' => '13:00:00',
    ]);

    $conflicts = reconciler()->findScheduleConflicts(
        $park,
        CarbonImmutable::parse($date),
        CarbonImmutable::parse($date),
    );

    expect($conflicts)->toBeEmpty();
});

test('findScheduleConflicts ignores past-dated schedules', function () {
    $park = reconcilerPark();
    $activity = ParkActivity::factory()->create(['park_id' => $park->id]);
    $pastDate = now()->subDays(5)->toDateString();
    $activity->schedules()->create([
        'date' => $pastDate,
        'start_time' => '09:00:00',
        'end_time' => '10:00:00',
    ]);
    $park->hourOverrides()->create([
        'date' => $pastDate,
        'open_time' => '11:00:00',
        'close_time' => '17:00:00',
    ]);

    $conflicts = reconciler()->findScheduleConflicts(
        $park,
        CarbonImmutable::parse(now()->subDays(10)->toDateString()),
        CarbonImmutable::parse(now()->subDays(1)->toDateString()),
    );
    expect($conflicts)->toBeEmpty();
});

test('findScheduleConflicts includes confirmed booking count', function () {
    $park = reconcilerPark();
    $activity = ParkActivity::factory()->create([
        'park_id' => $park->id,
        'price' => 30,
        'max_capacity' => 10,
    ]);
    $date = now()->addDays(5)->toDateString();
    $schedule = $activity->schedules()->create([
        'date' => $date,
        'start_time' => '09:00:00',
        'end_time' => '10:00:00',
    ]);
    $park->hourOverrides()->create([
        'date' => $date,
        'open_time' => '11:00:00',
        'close_time' => '17:00:00',
    ]);

    $customer = User::factory()->customer()->create();
    $reservation = Reservation::create(['user_id' => $customer->id]);
    ParkActivityBooking::create([
        'reservation_id' => $reservation->id,
        'park_activity_schedule_id' => $schedule->id,
        'guests' => 2,
        'status' => 'confirmed',
        'price_per_guest' => 30,
        'total_price' => 60,
    ]);

    $conflicts = reconciler()->findScheduleConflicts(
        $park,
        CarbonImmutable::parse($date),
        CarbonImmutable::parse($date),
    );

    expect($conflicts)->toHaveCount(1);
    expect($conflicts->first()['confirmed_bookings'])->toBe(1);
});

// cascadeCancel

test('cascadeCancel cancels schedules and their confirmed bookings', function () {
    $park = reconcilerPark();
    $activity = ParkActivity::factory()->create([
        'park_id' => $park->id,
        'price' => 30,
        'max_capacity' => 10,
    ]);
    $schedule = $activity->schedules()->create([
        'date' => now()->addDays(5)->toDateString(),
        'start_time' => '09:00:00',
        'end_time' => '10:00:00',
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

    $conflicts = collect([
        ['schedule' => $schedule, 'confirmed_bookings' => 1],
    ]);

    $result = reconciler()->cascadeCancel($conflicts, 'override applied');

    expect($result['schedules_cancelled'])->toBe(1);
    expect($result['bookings_cancelled'])->toBe(1);
    expect($schedule->fresh()->status)->toBe(ParkActivitySchedule::STATUS_CANCELLED);
    expect($booking->fresh()->status)->toBe('cancelled');
    expect($booking->fresh()->cancelled_at)->not->toBeNull();
    expect($schedule->fresh()->notes)->toContain('[cascade] override applied');
});

test('cascadeCancel preserves existing notes when appending audit reason', function () {
    $park = reconcilerPark();
    $activity = ParkActivity::factory()->create(['park_id' => $park->id]);
    $schedule = $activity->schedules()->create([
        'date' => now()->addDays(5)->toDateString(),
        'start_time' => '09:00:00',
        'end_time' => '10:00:00',
        'notes' => 'Original notes',
    ]);

    reconciler()->cascadeCancel(collect([
        ['schedule' => $schedule, 'confirmed_bookings' => 0],
    ]), 'override applied');

    $notes = $schedule->fresh()->notes;
    expect($notes)->toContain('Original notes');
    expect($notes)->toContain('[cascade] override applied');
});

test('cascadeCancel returns zeros for empty input', function () {
    $result = reconciler()->cascadeCancel(collect(), 'noop');
    expect($result)->toBe(['schedules_cancelled' => 0, 'bookings_cancelled' => 0]);
});
