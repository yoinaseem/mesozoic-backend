<?php

use App\Models\Hotel;
use App\Models\ParkActivity;
use App\Models\ParkActivityBooking;
use App\Models\ParkActivitySchedule;
use App\Models\ParkBooking;
use App\Models\ParkOpeningHour;
use App\Models\Reservation;
use App\Models\RoomBooking;
use App\Models\RoomType;
use App\Models\ThemePark;
use App\Models\User;
use Carbon\Carbon;

function allDayPark(string $open = '09:00:00', string $close = '21:00:00'): ThemePark
{
    $park = ThemePark::factory()->create(['capacity' => 100, 'price' => 50.0]);
    foreach (ParkOpeningHour::DAYS as $day) {
        $park->openingHours()->create([
            'day' => $day,
            'open_time' => $open,
            'close_time' => $close,
        ]);
    }

    return $park;
}

function allDaySetup(int $guests = 2): array
{
    $customer = User::factory()->customer()->create();
    $hotel = Hotel::factory()->create();
    $type = $hotel->roomTypes()->create([
        'name' => 'Standard',
        'capacity' => $guests,
        'price' => 100,
    ]);
    $hotel->rooms()->create(['room_type_id' => $type->id, 'room_no' => '101']);

    $reservation = Reservation::create(['user_id' => $customer->id]);
    $checkIn = now()->addDays(3)->toDateString();
    $checkOut = now()->addDays(6)->toDateString();
    RoomBooking::create([
        'reservation_id' => $reservation->id,
        'hotel_id' => $type->hotel_id,
        'room_type_id' => $type->id,
        'status' => 'confirmed',
        'check_in_date' => $checkIn,
        'check_out_date' => $checkOut,
        'guests' => $guests,
        'price_per_night' => $type->price,
        'nights' => 3,
        'total_price' => $type->price * 3,
    ]);

    return [$customer, $reservation, $checkIn];
}

function allDayDayPass(Reservation $reservation, ThemePark $park, string $date, int $guests = 2): ParkBooking
{
    return ParkBooking::create([
        'reservation_id' => $reservation->id,
        'park_id' => $park->id,
        'date' => $date,
        'guests' => $guests,
        'status' => 'confirmed',
        'price_per_guest' => $park->price,
        'total_price' => (float) $park->price * $guests,
    ]);
}

function allDayActivity(ThemePark $park, int $maxCapacity = 200, float $price = 30.0): ParkActivity
{
    return ParkActivity::factory()->create([
        'park_id' => $park->id,
        'is_all_day' => true,
        'duration' => null,
        'max_capacity' => $maxCapacity,
        'price' => $price,
    ]);
}

test('booking by (park_activity_id, date) materializes an all-day schedule with effective hours', function () {
    $park = allDayPark('09:00:00', '21:00:00');
    [$customer, $reservation, $checkIn] = allDaySetup();
    allDayDayPass($reservation, $park, $checkIn);
    $activity = allDayActivity($park);

    expect($activity->schedules()->count())->toBe(0);

    $this->actingAs($customer)
        ->postJson('/api/park-activity-bookings', [
            'reservation_id' => $reservation->id,
            'park_activity_id' => $activity->id,
            'date' => $checkIn,
            'guests' => 2,
        ])
        ->assertCreated();

    $schedule = $activity->schedules()->first();
    expect($schedule)->not->toBeNull();
    expect($schedule->date->toDateString())->toBe($checkIn);
    expect($schedule->start_time)->toBe('09:00:00');
    expect($schedule->end_time)->toBe('21:00:00');
});

test('second all-day booking on the same date reuses the materialized schedule', function () {
    $park = allDayPark();
    [$customerA, $reservationA, $checkIn] = allDaySetup();
    allDayDayPass($reservationA, $park, $checkIn);

    $customerB = User::factory()->customer()->create();
    $hotel = Hotel::factory()->create();
    $type = $hotel->roomTypes()->create(['name' => 'Std', 'capacity' => 2, 'price' => 100]);
    $hotel->rooms()->create(['room_type_id' => $type->id, 'room_no' => '101']);
    $reservationB = Reservation::create(['user_id' => $customerB->id]);
    RoomBooking::create([
        'reservation_id' => $reservationB->id,
        'hotel_id' => $type->hotel_id,
        'room_type_id' => $type->id,
        'status' => 'confirmed',
        'check_in_date' => $checkIn,
        'check_out_date' => Carbon::parse($checkIn)->addDays(3)->toDateString(),
        'guests' => 2,
        'price_per_night' => 100,
        'nights' => 3,
        'total_price' => 300,
    ]);
    allDayDayPass($reservationB, $park, $checkIn);

    $activity = allDayActivity($park);

    $this->actingAs($customerA)->postJson('/api/park-activity-bookings', [
        'reservation_id' => $reservationA->id,
        'park_activity_id' => $activity->id,
        'date' => $checkIn,
        'guests' => 2,
    ])->assertCreated();

    $this->actingAs($customerB)->postJson('/api/park-activity-bookings', [
        'reservation_id' => $reservationB->id,
        'park_activity_id' => $activity->id,
        'date' => $checkIn,
        'guests' => 2,
    ])->assertCreated();

    expect($activity->schedules()->count())->toBe(1);
    expect(ParkActivityBooking::where('park_activity_schedule_id', $activity->schedules()->first()->id)->count())->toBe(2);
});

test('all-day booking on a not_configured date is rejected', function () {
    $park = ThemePark::factory()->create();
    [$customer, $reservation, $checkIn] = allDaySetup();
    $activity = allDayActivity($park);

    $this->actingAs($customer)
        ->postJson('/api/park-activity-bookings', [
            'reservation_id' => $reservation->id,
            'park_activity_id' => $activity->id,
            'date' => $checkIn,
            'guests' => 2,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('date');

    expect($activity->schedules()->count())->toBe(0);
});

test('all-day booking on a closed-override date is rejected', function () {
    $park = allDayPark();
    [$customer, $reservation, $checkIn] = allDaySetup();
    $park->hourOverrides()->create([
        'date' => $checkIn,
        'open_time' => null,
        'close_time' => null,
    ]);
    $activity = allDayActivity($park);

    $this->actingAs($customer)
        ->postJson('/api/park-activity-bookings', [
            'reservation_id' => $reservation->id,
            'park_activity_id' => $activity->id,
            'date' => $checkIn,
            'guests' => 2,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('date');

    expect($activity->schedules()->count())->toBe(0);
});

test('booking with park_activity_id for a non-all-day activity is rejected', function () {
    $park = allDayPark();
    [$customer, $reservation, $checkIn] = allDaySetup();
    allDayDayPass($reservation, $park, $checkIn);
    $activity = ParkActivity::factory()->create([
        'park_id' => $park->id,
        'is_all_day' => false,
        'duration' => 60,
    ]);

    $this->actingAs($customer)
        ->postJson('/api/park-activity-bookings', [
            'reservation_id' => $reservation->id,
            'park_activity_id' => $activity->id,
            'date' => $checkIn,
            'guests' => 2,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('park_activity_id');
});

test('manual schedule creation for an all-day activity is rejected', function () {
    $park = allDayPark();
    $activity = allDayActivity($park);
    $manager = User::factory()->parkManager()->create();

    $this->actingAs($manager)
        ->postJson("/api/theme-parks/{$park->id}/activities/{$activity->id}/schedules", [
            'date' => now()->addDays(3)->toDateString(),
            'start_time' => '09:00:00',
            'end_time' => '21:00:00',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('park_activity_id');
});

test('override that narrows hours re-syncs the all-day schedule and keeps bookings confirmed', function () {
    $park = allDayPark('09:00:00', '21:00:00');
    [$customer, $reservation, $checkIn] = allDaySetup();
    allDayDayPass($reservation, $park, $checkIn);
    $activity = allDayActivity($park);

    // Materialize the schedule via a booking
    $bookingResponse = $this->actingAs($customer)->postJson('/api/park-activity-bookings', [
        'reservation_id' => $reservation->id,
        'park_activity_id' => $activity->id,
        'date' => $checkIn,
        'guests' => 2,
    ])->assertCreated();
    $bookingId = $bookingResponse->json('data.id');
    $schedule = $activity->schedules()->first();
    expect($schedule->start_time)->toBe('09:00:00');
    expect($schedule->end_time)->toBe('21:00:00');

    // Narrow hours via override + cascade
    $manager = User::factory()->parkManager()->create();
    $this->actingAs($manager)
        ->postJson("/api/theme-parks/{$park->id}/hour-overrides", [
            'date' => $checkIn,
            'open_time' => '11:00:00',
            'close_time' => '15:00:00',
            'on_conflict' => 'cascade',
        ])
        ->assertCreated()
        ->assertJsonPath('cascade.schedules_resynced', 1)
        ->assertJsonPath('cascade.schedules_cancelled', 0)
        ->assertJsonPath('cascade.bookings_cancelled', 0);

    expect($schedule->fresh()->status)->toBe(ParkActivitySchedule::STATUS_SCHEDULED);
    expect($schedule->fresh()->start_time)->toBe('11:00:00');
    expect($schedule->fresh()->end_time)->toBe('15:00:00');
    expect(ParkActivityBooking::find($bookingId)->status)->toBe('confirmed');
});

test('closed-day override + cascade cancels all-day schedule and bookings', function () {
    $park = allDayPark('09:00:00', '21:00:00');
    [$customer, $reservation, $checkIn] = allDaySetup();
    allDayDayPass($reservation, $park, $checkIn);
    $activity = allDayActivity($park);

    $bookingResponse = $this->actingAs($customer)->postJson('/api/park-activity-bookings', [
        'reservation_id' => $reservation->id,
        'park_activity_id' => $activity->id,
        'date' => $checkIn,
        'guests' => 2,
    ])->assertCreated();
    $bookingId = $bookingResponse->json('data.id');
    $schedule = $activity->schedules()->first();

    $manager = User::factory()->parkManager()->create();
    $this->actingAs($manager)
        ->postJson("/api/theme-parks/{$park->id}/hour-overrides", [
            'date' => $checkIn,
            'open_time' => null,
            'close_time' => null,
            'on_conflict' => 'cascade',
        ])
        ->assertCreated()
        ->assertJsonPath('cascade.schedules_cancelled', 1)
        ->assertJsonPath('cascade.bookings_cancelled', 1);

    expect($schedule->fresh()->status)->toBe(ParkActivitySchedule::STATUS_CANCELLED);
    expect(ParkActivityBooking::find($bookingId)->status)->toBe('cancelled');
});
