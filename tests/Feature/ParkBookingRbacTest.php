<?php

use App\Models\Hotel;
use App\Models\ParkBooking;
use App\Models\ParkOpeningHour;
use App\Models\Reservation;
use App\Models\RoomBooking;
use App\Models\RoomType;
use App\Models\ThemePark;
use App\Models\User;
use Carbon\Carbon;

function seedRoomType(int $capacity = 4, int $price = 100): RoomType
{
    $hotel = Hotel::factory()->create();

    /** @var RoomType $type */
    $type = $hotel->roomTypes()->create([
        'name'     => 'Standard',
        'capacity' => $capacity,
        'price'    => $price,
    ]);
    $hotel->rooms()->create(['room_type_id' => $type->id, 'room_no' => '101']);

    return $type;
}

function parkCustomer(): User
{
    $u = User::factory()->create();
    $u->assignRole('customer');

    return $u;
}

function parkManagerUser(): User
{
    $u = User::factory()->create();
    $u->assignRole('park-manager');

    return $u;
}

function confirmedRoomBooking(
    Reservation $reservation,
    RoomType $type,
    string $checkIn,
    string $checkOut,
    int $guests = 2,
    string $status = 'confirmed',
): RoomBooking {
    return RoomBooking::create([
        'reservation_id'  => $reservation->id,
        'hotel_id'        => $type->hotel_id,
        'room_type_id'    => $type->id,
        'status'          => $status,
        'check_in_date'   => $checkIn,
        'check_out_date'  => $checkOut,
        'guests'          => $guests,
        'price_per_night' => $type->price,
        'nights'          => Carbon::parse($checkIn)->diffInDays(Carbon::parse($checkOut)),
        'total_price'     => $type->price * Carbon::parse($checkIn)->diffInDays(Carbon::parse($checkOut)),
    ]);
}

function openEveryDayPark(int $capacity = 100, float $price = 50.0): ThemePark
{
    $park = ThemePark::factory()->create(['capacity' => $capacity, 'price' => $price]);

    foreach (ParkOpeningHour::DAYS as $day) {
        $park->openingHours()->create([
            'day'        => $day,
            'open_time'  => '09:00:00',
            'close_time' => '21:00:00',
        ]);
    }

    return $park;
}

/**
 * Build a 1-room reservation for a customer. 3-night stay starting +3 days,
 * so days +3, +4, +5 are bookable (+6 is check-out, excluded).
 */
function singleRoomReservation(User $customer, int $guests = 2): array
{
    $type        = seedRoomType();
    $reservation = Reservation::create(['user_id' => $customer->id]);
    $checkIn     = now()->addDays(3)->toDateString();
    $checkOut    = now()->addDays(6)->toDateString();
    confirmedRoomBooking($reservation, $type, $checkIn, $checkOut, $guests);

    return [$reservation, $checkIn, $checkOut];
}

test('unauthenticated request cannot list park bookings', function () {
    $this->getJson('/api/park-bookings')->assertUnauthorized();
});

test('customer creates a day pass within the stay window', function () {
    $customer                     = parkCustomer();
    [$reservation, $checkIn]      = singleRoomReservation($customer, guests: 2);
    $park                         = openEveryDayPark(price: 50.0);

    $this->actingAs($customer)->postJson('/api/park-bookings', [
        'reservation_id' => $reservation->id,
        'park_id'        => $park->id,
        'date'           => $checkIn,
        'guests'         => 2,
    ])
        ->assertCreated()
        ->assertJsonPath('data.reservation_id', $reservation->id)
        ->assertJsonPath('data.park_id', $park->id)
        ->assertJsonPath('data.guests', 2)
        ->assertJsonPath('data.status', 'confirmed')
        ->assertJsonPath('data.total_price', '100.00'); // 2 × $50
});

test('father books 3 rooms for a family of 6 and makes one park booking for all of them', function () {
    $father = parkCustomer();
    $typeA  = seedRoomType(capacity: 2);
    $typeB  = seedRoomType(capacity: 2);
    $typeC  = seedRoomType(capacity: 2);

    $reservation = Reservation::create(['user_id' => $father->id]);
    $checkIn     = now()->addDays(3)->toDateString();
    $checkOut    = now()->addDays(6)->toDateString();

    confirmedRoomBooking($reservation, $typeA, $checkIn, $checkOut, guests: 2); // parents
    confirmedRoomBooking($reservation, $typeB, $checkIn, $checkOut, guests: 2); // daughters
    confirmedRoomBooking($reservation, $typeC, $checkIn, $checkOut, guests: 2); // sons

    expect($reservation->seatPoolOn($checkIn))->toBe(6);

    $park = openEveryDayPark();

    $this->actingAs($father)->postJson('/api/park-bookings', [
        'reservation_id' => $reservation->id,
        'park_id'        => $park->id,
        'date'           => $checkIn,
        'guests'         => 6,
    ])
        ->assertCreated()
        ->assertJsonPath('data.guests', 6);

    expect(ParkBooking::where('reservation_id', $reservation->id)->count())->toBe(1);
});

test('guests cannot exceed the reservation seat pool on the date', function () {
    $customer                     = parkCustomer();
    [$reservation, $checkIn]      = singleRoomReservation($customer, guests: 2); // pool of 2
    $park                         = openEveryDayPark();

    $this->actingAs($customer)->postJson('/api/park-bookings', [
        'reservation_id' => $reservation->id,
        'park_id'        => $park->id,
        'date'           => $checkIn,
        'guests'         => 3,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['guests']);
});

test('guests can be fewer than the seat pool (partial group visit)', function () {
    $father = parkCustomer();
    $typeA  = seedRoomType(capacity: 2);
    $typeB  = seedRoomType(capacity: 2);

    $reservation = Reservation::create(['user_id' => $father->id]);
    $checkIn     = now()->addDays(3)->toDateString();
    $checkOut    = now()->addDays(6)->toDateString();
    confirmedRoomBooking($reservation, $typeA, $checkIn, $checkOut, guests: 2);
    confirmedRoomBooking($reservation, $typeB, $checkIn, $checkOut, guests: 2);

    $park = openEveryDayPark();

    // Pool is 4, only 2 go.
    $this->actingAs($father)->postJson('/api/park-bookings', [
        'reservation_id' => $reservation->id,
        'park_id'        => $park->id,
        'date'           => $checkIn,
        'guests'         => 2,
    ])
        ->assertCreated()
        ->assertJsonPath('data.guests', 2);
});

test('park booking on the check-out date is rejected (seat pool is 0)', function () {
    $customer                       = parkCustomer();
    [$reservation, , $checkOut]     = singleRoomReservation($customer);
    $park                           = openEveryDayPark();

    $this->actingAs($customer)->postJson('/api/park-bookings', [
        'reservation_id' => $reservation->id,
        'park_id'        => $park->id,
        'date'           => $checkOut,
        'guests'         => 2,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['date']);
});

test('park booking before check-in is rejected', function () {
    $customer                 = parkCustomer();
    [$reservation, $checkIn]  = singleRoomReservation($customer);
    $park                     = openEveryDayPark();

    $this->actingAs($customer)->postJson('/api/park-bookings', [
        'reservation_id' => $reservation->id,
        'park_id'        => $park->id,
        'date'           => Carbon::parse($checkIn)->subDay()->toDateString(),
        'guests'         => 2,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['date']);
});

test('park booking after checkout is rejected', function () {
    $customer                    = parkCustomer();
    [$reservation, , $checkOut]  = singleRoomReservation($customer);
    $park                        = openEveryDayPark();

    $this->actingAs($customer)->postJson('/api/park-bookings', [
        'reservation_id' => $reservation->id,
        'park_id'        => $park->id,
        'date'           => Carbon::parse($checkOut)->addDays(2)->toDateString(),
        'guests'         => 2,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['date']);
});

test('park booking on a closed override day is rejected', function () {
    $customer                 = parkCustomer();
    [$reservation, $checkIn]  = singleRoomReservation($customer);
    $park                     = openEveryDayPark();

    $park->hourOverrides()->create([
        'date'       => $checkIn,
        'open_time'  => null,
        'close_time' => null,
        'note'       => 'Sudden holiday',
    ]);

    $this->actingAs($customer)->postJson('/api/park-bookings', [
        'reservation_id' => $reservation->id,
        'park_id'        => $park->id,
        'date'           => $checkIn,
        'guests'         => 2,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['date']);
});

test('park booking with no baseline hours configured is rejected', function () {
    $customer                 = parkCustomer();
    [$reservation, $checkIn]  = singleRoomReservation($customer);
    $park                     = ThemePark::factory()->create(); // no opening hours

    $this->actingAs($customer)->postJson('/api/park-bookings', [
        'reservation_id' => $reservation->id,
        'park_id'        => $park->id,
        'date'           => $checkIn,
        'guests'         => 2,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['date']);
});

test('duplicate (reservation, park, date) rejected among confirmed rows', function () {
    $customer                 = parkCustomer();
    [$reservation, $checkIn]  = singleRoomReservation($customer);
    $park                     = openEveryDayPark();

    $this->actingAs($customer)->postJson('/api/park-bookings', [
        'reservation_id' => $reservation->id,
        'park_id'        => $park->id,
        'date'           => $checkIn,
        'guests'         => 2,
    ])->assertCreated();

    $this->actingAs($customer)->postJson('/api/park-bookings', [
        'reservation_id' => $reservation->id,
        'park_id'        => $park->id,
        'date'           => $checkIn,
        'guests'         => 2,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['date']);
});

test('same (reservation, date) is allowed for a different park', function () {
    $customer                 = parkCustomer();
    [$reservation, $checkIn]  = singleRoomReservation($customer, guests: 2);
    $parkA                    = openEveryDayPark();
    $parkB                    = openEveryDayPark();

    $this->actingAs($customer)->postJson('/api/park-bookings', [
        'reservation_id' => $reservation->id,
        'park_id'        => $parkA->id,
        'date'           => $checkIn,
        'guests'         => 2,
    ])->assertCreated();

    $this->actingAs($customer)->postJson('/api/park-bookings', [
        'reservation_id' => $reservation->id,
        'park_id'        => $parkB->id,
        'date'           => $checkIn,
        'guests'         => 2,
    ])->assertCreated();
});

test('same park-day pass can be re-booked after staff cancels the first', function () {
    $customer                 = parkCustomer();
    [$reservation, $checkIn]  = singleRoomReservation($customer);
    $park                     = openEveryDayPark();
    $manager                  = parkManagerUser();

    $first = $this->actingAs($customer)->postJson('/api/park-bookings', [
        'reservation_id' => $reservation->id,
        'park_id'        => $park->id,
        'date'           => $checkIn,
        'guests'         => 2,
    ])->assertCreated();

    // Cancellation is staff-only — park-manager issues the DELETE.
    $this->actingAs($manager)
        ->deleteJson('/api/park-bookings/'.$first->json('data.id'))
        ->assertNoContent();

    $this->actingAs($customer)->postJson('/api/park-bookings', [
        'reservation_id' => $reservation->id,
        'park_id'        => $park->id,
        'date'           => $checkIn,
        'guests'         => 2,
    ])->assertCreated();
});

test('park capacity cap blocks new bookings when full', function () {
    $customer                 = parkCustomer();
    [$reservation, $checkIn]  = singleRoomReservation($customer, guests: 2);
    $park                     = openEveryDayPark(capacity: 3); // only 3 seats total

    // Pre-fill 2 of 3 seats via another customer's reservation.
    $other                    = parkCustomer();
    [$otherRes]               = singleRoomReservation($other, guests: 2);

    // Align dates so both want the same day.
    ParkBooking::create([
        'reservation_id'  => $otherRes->id,
        'park_id'         => $park->id,
        'date'            => $checkIn,
        'guests'          => 2,
        'status'          => 'confirmed',
        'price_per_guest' => $park->price,
        'total_price'     => (float) $park->price * 2,
    ]);

    // Customer wants 2 more → 2 + 2 = 4 > 3 cap.
    $this->actingAs($customer)->postJson('/api/park-bookings', [
        'reservation_id' => $reservation->id,
        'park_id'        => $park->id,
        'date'           => $checkIn,
        'guests'         => 2,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['park_id']);
});

test('reservation with only cancelled rooms has empty seat pool → rejected', function () {
    $customer    = parkCustomer();
    $type        = seedRoomType();
    $reservation = Reservation::create(['user_id' => $customer->id]);
    confirmedRoomBooking(
        $reservation,
        $type,
        now()->addDays(3)->toDateString(),
        now()->addDays(6)->toDateString(),
        status: 'cancelled',
    );
    $park = openEveryDayPark();

    $this->actingAs($customer)->postJson('/api/park-bookings', [
        'reservation_id' => $reservation->id,
        'park_id'        => $park->id,
        'date'           => now()->addDays(3)->toDateString(),
        'guests'         => 1,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['date']);
});

test('customer cannot attach a park booking to another users reservation', function () {
    $owner                   = parkCustomer();
    $stranger                = parkCustomer();
    [$reservation, $checkIn] = singleRoomReservation($owner);
    $park                    = openEveryDayPark();

    $this->actingAs($stranger)->postJson('/api/park-bookings', [
        'reservation_id' => $reservation->id,
        'park_id'        => $park->id,
        'date'           => $checkIn,
        'guests'         => 2,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reservation_id']);
});

test('customer cannot update a park booking', function () {
    $customer                 = parkCustomer();
    [$reservation, $checkIn]  = singleRoomReservation($customer);
    $park                     = openEveryDayPark();

    $booking = ParkBooking::create([
        'reservation_id'  => $reservation->id,
        'park_id'         => $park->id,
        'date'            => $checkIn,
        'guests'          => 2,
        'status'          => 'confirmed',
        'price_per_guest' => $park->price,
        'total_price'     => (float) $park->price * 2,
    ]);

    $this->actingAs($customer)
        ->patchJson("/api/park-bookings/{$booking->id}", ['status' => 'cancelled'])
        ->assertForbidden();
});

test('customer cannot view another customers park booking', function () {
    $owner                   = parkCustomer();
    $stranger                = parkCustomer();
    [$reservation, $checkIn] = singleRoomReservation($owner);
    $park                    = openEveryDayPark();

    $booking = ParkBooking::create([
        'reservation_id'  => $reservation->id,
        'park_id'         => $park->id,
        'date'            => $checkIn,
        'guests'          => 2,
        'status'          => 'confirmed',
        'price_per_guest' => $park->price,
        'total_price'     => (float) $park->price * 2,
    ]);

    $this->actingAs($stranger)
        ->getJson("/api/park-bookings/{$booking->id}")
        ->assertForbidden();
});

test('customer cannot cancel own park booking — staff only', function () {
    // Cancellation is staff-only across every booking module (room, park,
    // beach, ferry, park-activity). Customer DELETE returns 403.
    $customer                = parkCustomer();
    [$reservation, $checkIn] = singleRoomReservation($customer);
    $park                    = openEveryDayPark();

    $booking = ParkBooking::create([
        'reservation_id'  => $reservation->id,
        'park_id'         => $park->id,
        'date'            => $checkIn,
        'guests'          => 2,
        'status'          => 'confirmed',
        'price_per_guest' => $park->price,
        'total_price'     => (float) $park->price * 2,
    ]);

    $this->actingAs($customer)
        ->deleteJson("/api/park-bookings/{$booking->id}")
        ->assertForbidden();

    $booking->refresh();
    expect($booking->status)->toBe('confirmed');
    expect($booking->cancelled_at)->toBeNull();
});

test('customer cannot cancel a park booking on the visit date', function () {
    $customer    = parkCustomer();
    $type        = seedRoomType();
    $reservation = Reservation::create(['user_id' => $customer->id]);
    confirmedRoomBooking(
        $reservation,
        $type,
        now()->subDay()->toDateString(),
        now()->addDays(2)->toDateString(),
    );
    $park = openEveryDayPark();

    $booking = ParkBooking::create([
        'reservation_id'  => $reservation->id,
        'park_id'         => $park->id,
        'date'            => now()->toDateString(), // today
        'guests'          => 2,
        'status'          => 'confirmed',
        'price_per_guest' => $park->price,
        'total_price'     => (float) $park->price * 2,
    ]);

    $this->actingAs($customer)
        ->deleteJson("/api/park-bookings/{$booking->id}")
        ->assertForbidden();
});

test('park-manager can cancel any park booking', function () {
    $customer                = parkCustomer();
    [$reservation, $checkIn] = singleRoomReservation($customer);
    $park                    = openEveryDayPark();
    $manager                 = parkManagerUser();

    $booking = ParkBooking::create([
        'reservation_id'  => $reservation->id,
        'park_id'         => $park->id,
        'date'            => $checkIn,
        'guests'          => 2,
        'status'          => 'confirmed',
        'price_per_guest' => $park->price,
        'total_price'     => (float) $park->price * 2,
    ]);

    $this->actingAs($manager)
        ->patchJson("/api/park-bookings/{$booking->id}", ['status' => 'cancelled'])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');
});

test('superadmin sees every park booking', function () {
    [$rA, $dA] = singleRoomReservation(parkCustomer());
    [$rB, $dB] = singleRoomReservation(parkCustomer());
    $park      = openEveryDayPark();

    foreach ([[$rA, $dA], [$rB, $dB]] as [$r, $d]) {
        ParkBooking::create([
            'reservation_id'  => $r->id,
            'park_id'         => $park->id,
            'date'            => $d,
            'guests'          => 2,
            'status'          => 'confirmed',
            'price_per_guest' => $park->price,
            'total_price'     => (float) $park->price * 2,
        ]);
    }

    $admin = User::factory()->create();
    $admin->assignRole('superadmin');

    $this->actingAs($admin)
        ->getJson('/api/park-bookings')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('customer index only returns their own park bookings', function () {
    $me    = parkCustomer();
    $other = parkCustomer();
    [$rMine, $dMine]     = singleRoomReservation($me);
    [$rTheirs, $dTheirs] = singleRoomReservation($other);
    $park                = openEveryDayPark();

    ParkBooking::create([
        'reservation_id'  => $rMine->id,
        'park_id'         => $park->id,
        'date'            => $dMine,
        'guests'          => 2,
        'status'          => 'confirmed',
        'price_per_guest' => $park->price,
        'total_price'     => (float) $park->price * 2,
    ]);
    ParkBooking::create([
        'reservation_id'  => $rTheirs->id,
        'park_id'         => $park->id,
        'date'            => $dTheirs,
        'guests'          => 2,
        'status'          => 'confirmed',
        'price_per_guest' => $park->price,
        'total_price'     => (float) $park->price * 2,
    ]);

    $this->actingAs($me)
        ->getJson('/api/park-bookings')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.reservation_id', $rMine->id);
});

test('historical park booking serializes its archived park (withTrashed)', function () {
    [$reservation, $checkIn] = singleRoomReservation(parkCustomer());
    $park = openEveryDayPark();

    $booking = \App\Models\ParkBooking::create([
        'reservation_id'  => $reservation->id,
        'park_id'         => $park->id,
        'date'            => $checkIn,
        'guests'          => 2,
        'status'          => 'confirmed',
        'price_per_guest' => $park->price,
        'total_price'     => (float) $park->price * 2,
    ]);

    // Archive the park after the booking exists.
    $park->delete();

    $admin = User::factory()->superadmin()->create();

    $response = $this->actingAs($admin)
        ->getJson("/api/park-bookings/{$booking->id}")
        ->assertOk();

    expect($response->json('data.park'))->not->toBeNull()
        ->and($response->json('data.park.id'))->toBe($park->id);
});

// PR 7 — day-pass cancel/date-change is blocked when activity bookings reference it

test('manager can cancel a day-pass with no linked activity bookings', function () {
    $customer                = parkCustomer();
    [$reservation, $checkIn] = singleRoomReservation($customer);
    $park                    = openEveryDayPark();
    $manager                 = parkManagerUser();

    $booking = \App\Models\ParkBooking::create([
        'reservation_id'  => $reservation->id,
        'park_id'         => $park->id,
        'date'            => $checkIn,
        'guests'          => 2,
        'status'          => 'confirmed',
        'price_per_guest' => $park->price,
        'total_price'     => (float) $park->price * 2,
    ]);

    $this->actingAs($manager)
        ->patchJson("/api/park-bookings/{$booking->id}", ['status' => 'cancelled'])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');
});

test('manager cannot cancel a day-pass that has linked confirmed activity bookings', function () {
    $customer                = parkCustomer();
    [$reservation, $checkIn] = singleRoomReservation($customer);
    $park                    = openEveryDayPark();
    $manager                 = parkManagerUser();

    $dayPass = \App\Models\ParkBooking::create([
        'reservation_id'  => $reservation->id,
        'park_id'         => $park->id,
        'date'            => $checkIn,
        'guests'          => 2,
        'status'          => 'confirmed',
        'price_per_guest' => $park->price,
        'total_price'     => (float) $park->price * 2,
    ]);

    $activity = \App\Models\ParkActivity::factory()->create([
        'park_id' => $park->id,
        'price' => 30,
        'max_capacity' => 10,
    ]);
    $schedule = $activity->schedules()->create([
        'date' => $checkIn,
        'start_time' => '10:00:00',
        'end_time' => '11:00:00',
        'status' => \App\Models\ParkActivitySchedule::STATUS_SCHEDULED,
    ]);
    \App\Models\ParkActivityBooking::create([
        'reservation_id' => $reservation->id,
        'park_activity_schedule_id' => $schedule->id,
        'guests' => 2,
        'status' => 'confirmed',
        'price_per_guest' => 30,
        'total_price' => 60,
    ]);

    $this->actingAs($manager)
        ->patchJson("/api/park-bookings/{$dayPass->id}", ['status' => 'cancelled'])
        ->assertStatus(409)
        ->assertJsonPath('blocking_bookings', 1);

    expect($dayPass->fresh()->status)->toBe('confirmed');
});

test('manager can move a day-pass date when no activity bookings exist on the old date', function () {
    $customer                = parkCustomer();
    [$reservation, $checkIn] = singleRoomReservation($customer);
    $newDate                 = Carbon::parse($checkIn)->addDay()->toDateString();
    $park                    = openEveryDayPark();
    $manager                 = parkManagerUser();

    $booking = \App\Models\ParkBooking::create([
        'reservation_id'  => $reservation->id,
        'park_id'         => $park->id,
        'date'            => $checkIn,
        'guests'          => 2,
        'status'          => 'confirmed',
        'price_per_guest' => $park->price,
        'total_price'     => (float) $park->price * 2,
    ]);

    $this->actingAs($manager)
        ->patchJson("/api/park-bookings/{$booking->id}", ['date' => $newDate])
        ->assertOk()
        ->assertJsonPath('data.date', $newDate);
});

test('manager cannot move a day-pass date when activity bookings exist on the old date', function () {
    $customer                = parkCustomer();
    [$reservation, $checkIn] = singleRoomReservation($customer);
    $newDate                 = Carbon::parse($checkIn)->addDay()->toDateString();
    $park                    = openEveryDayPark();
    $manager                 = parkManagerUser();

    $booking = \App\Models\ParkBooking::create([
        'reservation_id'  => $reservation->id,
        'park_id'         => $park->id,
        'date'            => $checkIn,
        'guests'          => 2,
        'status'          => 'confirmed',
        'price_per_guest' => $park->price,
        'total_price'     => (float) $park->price * 2,
    ]);

    $activity = \App\Models\ParkActivity::factory()->create([
        'park_id' => $park->id,
        'price' => 30,
        'max_capacity' => 10,
    ]);
    $schedule = $activity->schedules()->create([
        'date' => $checkIn,
        'start_time' => '10:00:00',
        'end_time' => '11:00:00',
        'status' => \App\Models\ParkActivitySchedule::STATUS_SCHEDULED,
    ]);
    \App\Models\ParkActivityBooking::create([
        'reservation_id' => $reservation->id,
        'park_activity_schedule_id' => $schedule->id,
        'guests' => 2,
        'status' => 'confirmed',
        'price_per_guest' => 30,
        'total_price' => 60,
    ]);

    $this->actingAs($manager)
        ->patchJson("/api/park-bookings/{$booking->id}", ['date' => $newDate])
        ->assertStatus(409)
        ->assertJsonPath('blocking_bookings', 1);

    expect($booking->fresh()->date->toDateString())->toBe($checkIn);
});
