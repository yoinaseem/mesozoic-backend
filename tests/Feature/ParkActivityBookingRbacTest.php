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

function paSeedRoomType(int $capacity = 4, int $price = 100): RoomType
{
    $hotel = Hotel::factory()->create();

    /** @var RoomType $type */
    $type = $hotel->roomTypes()->create([
        'name' => 'Standard',
        'capacity' => $capacity,
        'price' => $price,
    ]);
    $hotel->rooms()->create(['room_type_id' => $type->id, 'room_no' => '101']);

    return $type;
}

function paCustomer(): User
{
    $u = User::factory()->create();
    $u->assignRole('customer');

    return $u;
}

function paParkManager(): User
{
    $u = User::factory()->create();
    $u->assignRole('park-manager');

    return $u;
}

function paConfirmedRoomBooking(
    Reservation $reservation,
    RoomType $type,
    string $checkIn,
    string $checkOut,
    int $guests = 2,
    string $status = 'confirmed',
): RoomBooking {
    return RoomBooking::create([
        'reservation_id' => $reservation->id,
        'hotel_id' => $type->hotel_id,
        'room_type_id' => $type->id,
        'status' => $status,
        'check_in_date' => $checkIn,
        'check_out_date' => $checkOut,
        'guests' => $guests,
        'price_per_night' => $type->price,
        'nights' => Carbon::parse($checkIn)->diffInDays(Carbon::parse($checkOut)),
        'total_price' => $type->price * Carbon::parse($checkIn)->diffInDays(Carbon::parse($checkOut)),
    ]);
}

function paOpenEveryDayPark(int $capacity = 100, float $price = 50.0): ThemePark
{
    $park = ThemePark::factory()->create(['capacity' => $capacity, 'price' => $price]);

    foreach (ParkOpeningHour::DAYS as $day) {
        $park->openingHours()->create([
            'day' => $day,
            'open_time' => '09:00:00',
            'close_time' => '21:00:00',
        ]);
    }

    return $park;
}

function paActivityScheduleOn(
    ThemePark $park,
    string $date,
    int $maxCapacity = 20,
    float $price = 30.0,
    string $status = 'scheduled',
): ParkActivitySchedule {
    /** @var ParkActivity $activity */
    $activity = ParkActivity::factory()->create([
        'park_id' => $park->id,
        'max_capacity' => $maxCapacity,
        'price' => $price,
    ]);

    return $activity->schedules()->create([
        'date' => $date,
        'start_time' => '10:00:00',
        'end_time' => '11:00:00',
        'status' => $status,
    ]);
}

function paDayPass(Reservation $reservation, ThemePark $park, string $date, int $guests = 2): ParkBooking
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

/**
 * 1-room reservation, 3-night stay starting +3 days so +3, +4, +5 are bookable
 * and +6 is the excluded check-out day.
 */
function paSingleRoomReservation(User $customer, int $guests = 2): array
{
    $type = paSeedRoomType();
    $reservation = Reservation::create(['user_id' => $customer->id]);
    $checkIn = now()->addDays(3)->toDateString();
    $checkOut = now()->addDays(6)->toDateString();
    paConfirmedRoomBooking($reservation, $type, $checkIn, $checkOut, $guests);

    return [$reservation, $checkIn, $checkOut];
}

test('unauthenticated request cannot list park activity bookings', function () {
    $this->getJson('/api/park-activity-bookings')->assertUnauthorized();
});

test('customer books an activity with a day-pass in place', function () {
    $customer = paCustomer();
    [$reservation, $checkIn] = paSingleRoomReservation($customer, guests: 2);
    $park = paOpenEveryDayPark();
    paDayPass($reservation, $park, $checkIn, guests: 2);
    $schedule = paActivityScheduleOn($park, $checkIn, price: 30.0);

    $this->actingAs($customer)->postJson('/api/park-activity-bookings', [
        'reservation_id' => $reservation->id,
        'park_activity_schedule_id' => $schedule->id,
        'guests' => 2,
    ])
        ->assertCreated()
        ->assertJsonPath('data.reservation_id', $reservation->id)
        ->assertJsonPath('data.park_activity_schedule_id', $schedule->id)
        ->assertJsonPath('data.guests', 2)
        ->assertJsonPath('data.status', 'confirmed')
        ->assertJsonPath('data.total_price', '60.00'); // 2 × $30
});

test('booking an activity without a day-pass is rejected', function () {
    $customer = paCustomer();
    [$reservation, $checkIn] = paSingleRoomReservation($customer);
    $park = paOpenEveryDayPark();
    // No day-pass created.
    $schedule = paActivityScheduleOn($park, $checkIn);

    $this->actingAs($customer)->postJson('/api/park-activity-bookings', [
        'reservation_id' => $reservation->id,
        'park_activity_schedule_id' => $schedule->id,
        'guests' => 2,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reservation_id']);
});

test('activity guests cannot exceed day-pass guests', function () {
    $customer = paCustomer();
    [$reservation, $checkIn] = paSingleRoomReservation($customer, guests: 4);
    $park = paOpenEveryDayPark();
    paDayPass($reservation, $park, $checkIn, guests: 2); // only 2 on day-pass
    $schedule = paActivityScheduleOn($park, $checkIn);

    // Pool of 4, activity wants 3, but day-pass only covers 2.
    $this->actingAs($customer)->postJson('/api/park-activity-bookings', [
        'reservation_id' => $reservation->id,
        'park_activity_schedule_id' => $schedule->id,
        'guests' => 3,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['guests']);
});

test('cancelled day-pass does not count — booking rejected', function () {
    $customer = paCustomer();
    [$reservation, $checkIn] = paSingleRoomReservation($customer);
    $park = paOpenEveryDayPark();
    $dayPass = paDayPass($reservation, $park, $checkIn, guests: 2);
    $dayPass->update(['status' => 'cancelled']);
    $schedule = paActivityScheduleOn($park, $checkIn);

    $this->actingAs($customer)->postJson('/api/park-activity-bookings', [
        'reservation_id' => $reservation->id,
        'park_activity_schedule_id' => $schedule->id,
        'guests' => 2,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reservation_id']);
});

test('guests cannot exceed the reservation seat pool on the schedule date', function () {
    $customer = paCustomer();
    [$reservation, $checkIn] = paSingleRoomReservation($customer, guests: 2);
    $park = paOpenEveryDayPark();
    paDayPass($reservation, $park, $checkIn, guests: 2);
    $schedule = paActivityScheduleOn($park, $checkIn);

    $this->actingAs($customer)->postJson('/api/park-activity-bookings', [
        'reservation_id' => $reservation->id,
        'park_activity_schedule_id' => $schedule->id,
        'guests' => 3,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['guests']);
});

test('activity booking on check-out date is rejected (seat pool is 0)', function () {
    $customer = paCustomer();
    [$reservation, , $checkOut] = paSingleRoomReservation($customer);
    $park = paOpenEveryDayPark();
    paDayPass($reservation, $park, $checkOut, guests: 2); // day-pass on checkout date is itself invalid in park booking rules, but we seed directly
    $schedule = paActivityScheduleOn($park, $checkOut);

    $this->actingAs($customer)->postJson('/api/park-activity-bookings', [
        'reservation_id' => $reservation->id,
        'park_activity_schedule_id' => $schedule->id,
        'guests' => 2,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['park_activity_schedule_id']);
});

test('activity booking before check-in is rejected', function () {
    $customer = paCustomer();
    [$reservation, $checkIn] = paSingleRoomReservation($customer);
    $park = paOpenEveryDayPark();
    $before = Carbon::parse($checkIn)->subDay()->toDateString();
    $schedule = paActivityScheduleOn($park, $before);

    $this->actingAs($customer)->postJson('/api/park-activity-bookings', [
        'reservation_id' => $reservation->id,
        'park_activity_schedule_id' => $schedule->id,
        'guests' => 2,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['park_activity_schedule_id']);
});

test('activity booking after checkout is rejected', function () {
    $customer = paCustomer();
    [$reservation, , $checkOut] = paSingleRoomReservation($customer);
    $park = paOpenEveryDayPark();
    $after = Carbon::parse($checkOut)->addDays(2)->toDateString();
    $schedule = paActivityScheduleOn($park, $after);

    $this->actingAs($customer)->postJson('/api/park-activity-bookings', [
        'reservation_id' => $reservation->id,
        'park_activity_schedule_id' => $schedule->id,
        'guests' => 2,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['park_activity_schedule_id']);
});

test('booking a cancelled schedule is rejected', function () {
    $customer = paCustomer();
    [$reservation, $checkIn] = paSingleRoomReservation($customer);
    $park = paOpenEveryDayPark();
    paDayPass($reservation, $park, $checkIn, guests: 2);
    $schedule = paActivityScheduleOn($park, $checkIn, status: 'cancelled');

    $this->actingAs($customer)->postJson('/api/park-activity-bookings', [
        'reservation_id' => $reservation->id,
        'park_activity_schedule_id' => $schedule->id,
        'guests' => 2,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['park_activity_schedule_id']);
});

test('booking a completed schedule is rejected', function () {
    $customer = paCustomer();
    [$reservation, $checkIn] = paSingleRoomReservation($customer);
    $park = paOpenEveryDayPark();
    paDayPass($reservation, $park, $checkIn, guests: 2);
    $schedule = paActivityScheduleOn($park, $checkIn, status: 'completed');

    $this->actingAs($customer)->postJson('/api/park-activity-bookings', [
        'reservation_id' => $reservation->id,
        'park_activity_schedule_id' => $schedule->id,
        'guests' => 2,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['park_activity_schedule_id']);
});

test('booking an activity on a closed override day is rejected', function () {
    $customer = paCustomer();
    [$reservation, $checkIn] = paSingleRoomReservation($customer);
    $park = paOpenEveryDayPark();
    paDayPass($reservation, $park, $checkIn, guests: 2);
    $park->hourOverrides()->create([
        'date' => $checkIn,
        'open_time' => null,
        'close_time' => null,
        'note' => 'Sudden holiday',
    ]);
    $schedule = paActivityScheduleOn($park, $checkIn);

    $this->actingAs($customer)->postJson('/api/park-activity-bookings', [
        'reservation_id' => $reservation->id,
        'park_activity_schedule_id' => $schedule->id,
        'guests' => 2,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['park_activity_schedule_id']);
});

test('duplicate (reservation, schedule) rejected among confirmed rows', function () {
    $customer = paCustomer();
    [$reservation, $checkIn] = paSingleRoomReservation($customer);
    $park = paOpenEveryDayPark();
    paDayPass($reservation, $park, $checkIn, guests: 2);
    $schedule = paActivityScheduleOn($park, $checkIn);

    $this->actingAs($customer)->postJson('/api/park-activity-bookings', [
        'reservation_id' => $reservation->id,
        'park_activity_schedule_id' => $schedule->id,
        'guests' => 2,
    ])->assertCreated();

    $this->actingAs($customer)->postJson('/api/park-activity-bookings', [
        'reservation_id' => $reservation->id,
        'park_activity_schedule_id' => $schedule->id,
        'guests' => 2,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['park_activity_schedule_id']);
});

test('same reservation+date allowed on a different activity schedule', function () {
    $customer = paCustomer();
    [$reservation, $checkIn] = paSingleRoomReservation($customer);
    $park = paOpenEveryDayPark();
    paDayPass($reservation, $park, $checkIn, guests: 2);
    $scheduleA = paActivityScheduleOn($park, $checkIn);
    $scheduleB = paActivityScheduleOn($park, $checkIn);

    $this->actingAs($customer)->postJson('/api/park-activity-bookings', [
        'reservation_id' => $reservation->id,
        'park_activity_schedule_id' => $scheduleA->id,
        'guests' => 2,
    ])->assertCreated();

    $this->actingAs($customer)->postJson('/api/park-activity-bookings', [
        'reservation_id' => $reservation->id,
        'park_activity_schedule_id' => $scheduleB->id,
        'guests' => 2,
    ])->assertCreated();
});

test('activity max_capacity cap blocks new bookings when full', function () {
    $customer = paCustomer();
    [$reservation, $checkIn] = paSingleRoomReservation($customer, guests: 2);
    $park = paOpenEveryDayPark();
    paDayPass($reservation, $park, $checkIn, guests: 2);
    $schedule = paActivityScheduleOn($park, $checkIn, maxCapacity: 3);

    // Pre-fill 2 seats via another customer.
    $other = paCustomer();
    [$otherRes] = paSingleRoomReservation($other, guests: 2);
    paDayPass($otherRes, $park, $checkIn, guests: 2);

    ParkActivityBooking::create([
        'reservation_id' => $otherRes->id,
        'park_activity_schedule_id' => $schedule->id,
        'guests' => 2,
        'status' => 'confirmed',
        'price_per_guest' => $schedule->parkActivity->price,
        'total_price' => (float) $schedule->parkActivity->price * 2,
    ]);

    // 2 + 2 = 4 > 3 cap.
    $this->actingAs($customer)->postJson('/api/park-activity-bookings', [
        'reservation_id' => $reservation->id,
        'park_activity_schedule_id' => $schedule->id,
        'guests' => 2,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['park_activity_schedule_id']);
});

test('reservation with only cancelled rooms has empty seat pool → rejected', function () {
    $customer = paCustomer();
    $type = paSeedRoomType();
    $reservation = Reservation::create(['user_id' => $customer->id]);
    $checkIn = now()->addDays(3)->toDateString();
    paConfirmedRoomBooking(
        $reservation,
        $type,
        $checkIn,
        now()->addDays(6)->toDateString(),
        status: 'cancelled',
    );
    $park = paOpenEveryDayPark();
    paDayPass($reservation, $park, $checkIn, guests: 1);
    $schedule = paActivityScheduleOn($park, $checkIn);

    $this->actingAs($customer)->postJson('/api/park-activity-bookings', [
        'reservation_id' => $reservation->id,
        'park_activity_schedule_id' => $schedule->id,
        'guests' => 1,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['park_activity_schedule_id']);
});

test('customer cannot attach an activity booking to another users reservation', function () {
    $owner = paCustomer();
    $stranger = paCustomer();
    [$reservation, $checkIn] = paSingleRoomReservation($owner);
    $park = paOpenEveryDayPark();
    paDayPass($reservation, $park, $checkIn, guests: 2);
    $schedule = paActivityScheduleOn($park, $checkIn);

    $this->actingAs($stranger)->postJson('/api/park-activity-bookings', [
        'reservation_id' => $reservation->id,
        'park_activity_schedule_id' => $schedule->id,
        'guests' => 2,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reservation_id']);
});

test('customer cannot update an activity booking', function () {
    $customer = paCustomer();
    [$reservation, $checkIn] = paSingleRoomReservation($customer);
    $park = paOpenEveryDayPark();
    paDayPass($reservation, $park, $checkIn, guests: 2);
    $schedule = paActivityScheduleOn($park, $checkIn);

    $booking = ParkActivityBooking::create([
        'reservation_id' => $reservation->id,
        'park_activity_schedule_id' => $schedule->id,
        'guests' => 2,
        'status' => 'confirmed',
        'price_per_guest' => $schedule->parkActivity->price,
        'total_price' => (float) $schedule->parkActivity->price * 2,
    ]);

    $this->actingAs($customer)
        ->patchJson("/api/park-activity-bookings/{$booking->id}", ['status' => 'cancelled'])
        ->assertForbidden();
});

test('customer cannot cancel their own activity booking (staff only)', function () {
    $customer = paCustomer();
    [$reservation, $checkIn] = paSingleRoomReservation($customer);
    $park = paOpenEveryDayPark();
    paDayPass($reservation, $park, $checkIn, guests: 2);
    $schedule = paActivityScheduleOn($park, $checkIn);

    $booking = ParkActivityBooking::create([
        'reservation_id' => $reservation->id,
        'park_activity_schedule_id' => $schedule->id,
        'guests' => 2,
        'status' => 'confirmed',
        'price_per_guest' => $schedule->parkActivity->price,
        'total_price' => (float) $schedule->parkActivity->price * 2,
    ]);

    $this->actingAs($customer)
        ->deleteJson("/api/park-activity-bookings/{$booking->id}")
        ->assertForbidden();
});

test('customer cannot view another customers activity booking', function () {
    $owner = paCustomer();
    $stranger = paCustomer();
    [$reservation, $checkIn] = paSingleRoomReservation($owner);
    $park = paOpenEveryDayPark();
    paDayPass($reservation, $park, $checkIn, guests: 2);
    $schedule = paActivityScheduleOn($park, $checkIn);

    $booking = ParkActivityBooking::create([
        'reservation_id' => $reservation->id,
        'park_activity_schedule_id' => $schedule->id,
        'guests' => 2,
        'status' => 'confirmed',
        'price_per_guest' => $schedule->parkActivity->price,
        'total_price' => (float) $schedule->parkActivity->price * 2,
    ]);

    $this->actingAs($stranger)
        ->getJson("/api/park-activity-bookings/{$booking->id}")
        ->assertForbidden();
});

test('park-manager can cancel any activity booking', function () {
    $customer = paCustomer();
    [$reservation, $checkIn] = paSingleRoomReservation($customer);
    $park = paOpenEveryDayPark();
    paDayPass($reservation, $park, $checkIn, guests: 2);
    $schedule = paActivityScheduleOn($park, $checkIn);
    $manager = paParkManager();

    $booking = ParkActivityBooking::create([
        'reservation_id' => $reservation->id,
        'park_activity_schedule_id' => $schedule->id,
        'guests' => 2,
        'status' => 'confirmed',
        'price_per_guest' => $schedule->parkActivity->price,
        'total_price' => (float) $schedule->parkActivity->price * 2,
    ]);

    $this->actingAs($manager)
        ->deleteJson("/api/park-activity-bookings/{$booking->id}")
        ->assertNoContent();

    $booking->refresh();
    expect($booking->status)->toBe('cancelled');
    expect($booking->cancelled_at)->not->toBeNull();
});

test('park-manager can update booking status', function () {
    $customer = paCustomer();
    [$reservation, $checkIn] = paSingleRoomReservation($customer);
    $park = paOpenEveryDayPark();
    paDayPass($reservation, $park, $checkIn, guests: 2);
    $schedule = paActivityScheduleOn($park, $checkIn);
    $manager = paParkManager();

    $booking = ParkActivityBooking::create([
        'reservation_id' => $reservation->id,
        'park_activity_schedule_id' => $schedule->id,
        'guests' => 2,
        'status' => 'confirmed',
        'price_per_guest' => $schedule->parkActivity->price,
        'total_price' => (float) $schedule->parkActivity->price * 2,
    ]);

    $this->actingAs($manager)
        ->patchJson("/api/park-activity-bookings/{$booking->id}", ['status' => 'cancelled'])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');
});

test('superadmin sees every activity booking', function () {
    [$rA, $dA] = paSingleRoomReservation(paCustomer());
    [$rB, $dB] = paSingleRoomReservation(paCustomer());
    $park = paOpenEveryDayPark();
    paDayPass($rA, $park, $dA, guests: 2);
    paDayPass($rB, $park, $dB, guests: 2);
    $sA = paActivityScheduleOn($park, $dA);
    $sB = paActivityScheduleOn($park, $dB);

    foreach ([[$rA, $sA], [$rB, $sB]] as [$r, $s]) {
        ParkActivityBooking::create([
            'reservation_id' => $r->id,
            'park_activity_schedule_id' => $s->id,
            'guests' => 2,
            'status' => 'confirmed',
            'price_per_guest' => $s->parkActivity->price,
            'total_price' => (float) $s->parkActivity->price * 2,
        ]);
    }

    $admin = User::factory()->create();
    $admin->assignRole('superadmin');

    $this->actingAs($admin)
        ->getJson('/api/park-activity-bookings')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('customer index only returns their own activity bookings', function () {
    $me = paCustomer();
    $other = paCustomer();
    [$rMine, $dMine] = paSingleRoomReservation($me);
    [$rTheirs, $dTheirs] = paSingleRoomReservation($other);
    $park = paOpenEveryDayPark();
    paDayPass($rMine, $park, $dMine, guests: 2);
    paDayPass($rTheirs, $park, $dTheirs, guests: 2);
    $sMine = paActivityScheduleOn($park, $dMine);
    $sTheirs = paActivityScheduleOn($park, $dTheirs);

    ParkActivityBooking::create([
        'reservation_id' => $rMine->id,
        'park_activity_schedule_id' => $sMine->id,
        'guests' => 2,
        'status' => 'confirmed',
        'price_per_guest' => $sMine->parkActivity->price,
        'total_price' => (float) $sMine->parkActivity->price * 2,
    ]);
    ParkActivityBooking::create([
        'reservation_id' => $rTheirs->id,
        'park_activity_schedule_id' => $sTheirs->id,
        'guests' => 2,
        'status' => 'confirmed',
        'price_per_guest' => $sTheirs->parkActivity->price,
        'total_price' => (float) $sTheirs->parkActivity->price * 2,
    ]);

    $this->actingAs($me)
        ->getJson('/api/park-activity-bookings')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.reservation_id', $rMine->id);
});

test('historical activity booking serializes its archived schedule+activity+park chain', function () {
    $customer = paCustomer();
    [$reservation, $checkIn] = paSingleRoomReservation($customer);
    $park = paOpenEveryDayPark();
    $activity = ParkActivity::factory()->create([
        'park_id'      => $park->id,
        'price'        => 30,
        'duration'     => 60,
        'max_capacity' => 10,
    ]);
    $schedule = $activity->schedules()->create([
        'date'       => $checkIn,
        'start_time' => '10:00:00',
        'end_time'   => '11:00:00',
        'status'     => ParkActivitySchedule::STATUS_SCHEDULED,
    ]);

    $booking = ParkActivityBooking::create([
        'reservation_id'            => $reservation->id,
        'park_activity_schedule_id' => $schedule->id,
        'guests'                    => 1,
        'status'                    => 'confirmed',
        'price_per_guest'           => $activity->price,
        'total_price'               => (float) $activity->price,
    ]);

    // Archive the whole chain via park archive (cascades).
    $park->delete();

    $admin = User::factory()->superadmin()->create();

    $response = $this->actingAs($admin)
        ->getJson("/api/park-activity-bookings/{$booking->id}")
        ->assertOk();

    expect($response->json('data.schedule'))->not->toBeNull()
        ->and($response->json('data.schedule.id'))->toBe($schedule->id);
});
