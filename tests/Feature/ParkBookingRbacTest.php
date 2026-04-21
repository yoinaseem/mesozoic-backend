<?php

use App\Models\Hotel;
use App\Models\ParkBooking;
use App\Models\Reservation;
use App\Models\RoomBooking;
use App\Models\RoomType;
use App\Models\ThemePark;
use App\Models\User;
use Carbon\Carbon;

function seedRoomSetup(int $capacity = 4, int $price = 100): array
{
    $hotel = Hotel::factory()->create();
    $type  = $hotel->roomTypes()->create([
        'name'     => 'Standard',
        'capacity' => $capacity,
        'price'    => $price,
    ]);
    $hotel->rooms()->create(['room_type_id' => $type->id, 'room_no' => '101']);

    return [$hotel, $type];
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

/**
 * Park with a 9-to-9 baseline for every weekday, so any future date the tests
 * pick is "open" unless a closed-day override is attached.
 */
function openEveryDayPark(int $capacity = 100, float $price = 50.0): ThemePark
{
    $park = ThemePark::factory()->create(['capacity' => $capacity, 'price' => $price]);

    foreach (\App\Models\ParkOpeningHour::DAYS as $day) {
        $park->openingHours()->create([
            'day'        => $day,
            'open_time'  => '09:00:00',
            'close_time' => '21:00:00',
        ]);
    }

    return $park;
}

function bookedStayFor(User $customer, RoomType $type, int $guests = 2): array
{
    $reservation = Reservation::create(['user_id' => $customer->id]);
    $checkIn     = now()->addDays(3)->toDateString();
    $checkOut    = now()->addDays(6)->toDateString(); // 3-night stay: +3, +4, +5 are bookable; +6 is checkout
    $rb          = confirmedRoomBooking($reservation, $type, $checkIn, $checkOut, $guests);

    return [$rb, $checkIn, $checkOut];
}

test('unauthenticated request cannot list park bookings', function () {
    $this->getJson('/api/park-bookings')->assertUnauthorized();
});

test('customer creates a day pass within the stay window', function () {
    [, $type]                 = seedRoomSetup();
    $customer                 = parkCustomer();
    [$rb, $checkIn]           = bookedStayFor($customer, $type, guests: 2);
    $park                     = openEveryDayPark(price: 50.0);

    $this->actingAs($customer)->postJson('/api/park-bookings', [
        'room_booking_id' => $rb->id,
        'park_id'         => $park->id,
        'date'            => $checkIn,
    ])
        ->assertCreated()
        ->assertJsonPath('data.room_booking_id', $rb->id)
        ->assertJsonPath('data.park_id', $park->id)
        ->assertJsonPath('data.guests', 2)            // inherited from room booking
        ->assertJsonPath('data.status', 'confirmed')
        ->assertJsonPath('data.total_price', '100.00'); // 2 guests × $50
});

test('guests are always inherited from the room booking (not accepted from client)', function () {
    [, $type]       = seedRoomSetup();
    $customer       = parkCustomer();
    [$rb, $checkIn] = bookedStayFor($customer, $type, guests: 3);
    $park           = openEveryDayPark();

    $response = $this->actingAs($customer)->postJson('/api/park-bookings', [
        'room_booking_id' => $rb->id,
        'park_id'         => $park->id,
        'date'            => $checkIn,
        'guests'          => 99, // attempt to override — should be ignored
    ])->assertCreated();

    expect($response->json('data.guests'))->toBe(3);
});

test('park booking on the check-out date is rejected', function () {
    [, $type]                  = seedRoomSetup();
    $customer                  = parkCustomer();
    [$rb, , $checkOut]         = bookedStayFor($customer, $type);
    $park                      = openEveryDayPark();

    $this->actingAs($customer)->postJson('/api/park-bookings', [
        'room_booking_id' => $rb->id,
        'park_id'         => $park->id,
        'date'            => $checkOut,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['date']);
});

test('park booking before check-in is rejected', function () {
    [, $type]       = seedRoomSetup();
    $customer       = parkCustomer();
    [$rb, $checkIn] = bookedStayFor($customer, $type);
    $park           = openEveryDayPark();

    $dayBeforeCheckIn = Carbon::parse($checkIn)->subDay()->toDateString();

    $this->actingAs($customer)->postJson('/api/park-bookings', [
        'room_booking_id' => $rb->id,
        'park_id'         => $park->id,
        'date'            => $dayBeforeCheckIn,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['date']);
});

test('park booking after checkout is rejected', function () {
    [, $type]                   = seedRoomSetup();
    $customer                   = parkCustomer();
    [$rb, , $checkOut]          = bookedStayFor($customer, $type);
    $park                       = openEveryDayPark();

    $this->actingAs($customer)->postJson('/api/park-bookings', [
        'room_booking_id' => $rb->id,
        'park_id'         => $park->id,
        'date'            => Carbon::parse($checkOut)->addDays(2)->toDateString(),
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['date']);
});

test('park booking on a closed override day is rejected', function () {
    [, $type]       = seedRoomSetup();
    $customer       = parkCustomer();
    [$rb, $checkIn] = bookedStayFor($customer, $type);
    $park           = openEveryDayPark();

    // Sudden holiday: null open/close = closed that day.
    $park->hourOverrides()->create([
        'date'       => $checkIn,
        'open_time'  => null,
        'close_time' => null,
        'note'       => 'Sudden holiday',
    ]);

    $this->actingAs($customer)->postJson('/api/park-bookings', [
        'room_booking_id' => $rb->id,
        'park_id'         => $park->id,
        'date'            => $checkIn,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['date']);
});

test('park booking with no baseline hours configured is rejected (not_configured = closed)', function () {
    [, $type]       = seedRoomSetup();
    $customer       = parkCustomer();
    [$rb, $checkIn] = bookedStayFor($customer, $type);
    $park           = ThemePark::factory()->create(); // no opening hours configured

    $this->actingAs($customer)->postJson('/api/park-bookings', [
        'room_booking_id' => $rb->id,
        'park_id'         => $park->id,
        'date'            => $checkIn,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['date']);
});

test('one day-pass per room booking per date — duplicate rejected', function () {
    [, $type]       = seedRoomSetup();
    $customer       = parkCustomer();
    [$rb, $checkIn] = bookedStayFor($customer, $type);
    $park           = openEveryDayPark();

    $this->actingAs($customer)->postJson('/api/park-bookings', [
        'room_booking_id' => $rb->id,
        'park_id'         => $park->id,
        'date'            => $checkIn,
    ])->assertCreated();

    $this->actingAs($customer)->postJson('/api/park-bookings', [
        'room_booking_id' => $rb->id,
        'park_id'         => $park->id,
        'date'            => $checkIn,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['date']);
});

test('same day pass can be re-booked after the first is cancelled', function () {
    [, $type]       = seedRoomSetup();
    $customer       = parkCustomer();
    [$rb, $checkIn] = bookedStayFor($customer, $type);
    $park           = openEveryDayPark();

    $first = $this->actingAs($customer)->postJson('/api/park-bookings', [
        'room_booking_id' => $rb->id,
        'park_id'         => $park->id,
        'date'            => $checkIn,
    ])->assertCreated();

    $this->actingAs($customer)->deleteJson('/api/park-bookings/'.$first->json('data.id'))->assertNoContent();

    $this->actingAs($customer)->postJson('/api/park-bookings', [
        'room_booking_id' => $rb->id,
        'park_id'         => $park->id,
        'date'            => $checkIn,
    ])->assertCreated();
});

test('park capacity cap blocks new bookings when full', function () {
    [, $type]       = seedRoomSetup();
    $customer       = parkCustomer();
    [$rb, $checkIn] = bookedStayFor($customer, $type, guests: 2);
    $park           = openEveryDayPark(capacity: 3); // only 3 seats total for the day

    // Pre-fill 2 of 3 seats via a sibling booking (different room booking / reservation).
    $other            = parkCustomer();
    [, $otherType]    = seedRoomSetup();
    [$otherRb]        = bookedStayFor($other, $otherType, guests: 2);
    ParkBooking::create([
        'room_booking_id' => $otherRb->id,
        'park_id'         => $park->id,
        'date'            => $checkIn,
        'guests'          => 2,
        'status'          => 'confirmed',
        'price_per_guest' => $park->price,
        'total_price'     => (float) $park->price * 2,
    ]);

    // Customer's 2 guests + 2 existing = 4 > capacity 3 → rejected.
    $this->actingAs($customer)->postJson('/api/park-bookings', [
        'room_booking_id' => $rb->id,
        'park_id'         => $park->id,
        'date'            => $checkIn,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['park_id']);
});

test('room booking must be confirmed — cancelled room booking rejects park booking', function () {
    [, $type]        = seedRoomSetup();
    $customer        = parkCustomer();
    $reservation     = Reservation::create(['user_id' => $customer->id]);
    $rb              = confirmedRoomBooking(
        $reservation,
        $type,
        now()->addDays(3)->toDateString(),
        now()->addDays(6)->toDateString(),
        status: 'cancelled',
    );
    $park            = openEveryDayPark();

    $this->actingAs($customer)->postJson('/api/park-bookings', [
        'room_booking_id' => $rb->id,
        'park_id'         => $park->id,
        'date'            => now()->addDays(3)->toDateString(),
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['room_booking_id']);
});

test('customer cannot attach a park booking to another users room booking', function () {
    [, $type]  = seedRoomSetup();
    $owner     = parkCustomer();
    $stranger  = parkCustomer();
    [$rb, $checkIn] = bookedStayFor($owner, $type);
    $park      = openEveryDayPark();

    $this->actingAs($stranger)->postJson('/api/park-bookings', [
        'room_booking_id' => $rb->id,
        'park_id'         => $park->id,
        'date'            => $checkIn,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['room_booking_id']);
});

test('customer cannot update a park booking', function () {
    [, $type]       = seedRoomSetup();
    $customer       = parkCustomer();
    [$rb, $checkIn] = bookedStayFor($customer, $type);
    $park           = openEveryDayPark();

    $booking = ParkBooking::create([
        'room_booking_id' => $rb->id,
        'park_id'         => $park->id,
        'date'            => $checkIn,
        'guests'          => $rb->guests,
        'status'          => 'confirmed',
        'price_per_guest' => $park->price,
        'total_price'     => (float) $park->price * $rb->guests,
    ]);

    $this->actingAs($customer)
        ->patchJson("/api/park-bookings/{$booking->id}", ['status' => 'cancelled'])
        ->assertForbidden();
});

test('customer cannot view another customers park booking', function () {
    [, $type]       = seedRoomSetup();
    $owner          = parkCustomer();
    $stranger       = parkCustomer();
    [$rb, $checkIn] = bookedStayFor($owner, $type);
    $park           = openEveryDayPark();

    $booking = ParkBooking::create([
        'room_booking_id' => $rb->id,
        'park_id'         => $park->id,
        'date'            => $checkIn,
        'guests'          => $rb->guests,
        'status'          => 'confirmed',
        'price_per_guest' => $park->price,
        'total_price'     => (float) $park->price * $rb->guests,
    ]);

    $this->actingAs($stranger)
        ->getJson("/api/park-bookings/{$booking->id}")
        ->assertForbidden();
});

test('customer cancels own park booking before the visit date', function () {
    [, $type]       = seedRoomSetup();
    $customer       = parkCustomer();
    [$rb, $checkIn] = bookedStayFor($customer, $type);
    $park           = openEveryDayPark();

    $booking = ParkBooking::create([
        'room_booking_id' => $rb->id,
        'park_id'         => $park->id,
        'date'            => $checkIn,
        'guests'          => $rb->guests,
        'status'          => 'confirmed',
        'price_per_guest' => $park->price,
        'total_price'     => (float) $park->price * $rb->guests,
    ]);

    $this->actingAs($customer)
        ->deleteJson("/api/park-bookings/{$booking->id}")
        ->assertNoContent();

    $booking->refresh();
    expect($booking->status)->toBe('cancelled');
    expect($booking->cancelled_at)->not->toBeNull();
});

test('customer cannot cancel a park booking on or after the visit date', function () {
    [, $type]        = seedRoomSetup();
    $customer        = parkCustomer();
    $reservation     = Reservation::create(['user_id' => $customer->id]);
    $rb              = confirmedRoomBooking(
        $reservation,
        $type,
        now()->subDay()->toDateString(),
        now()->addDays(2)->toDateString(),
    );
    $park            = openEveryDayPark();

    $booking = ParkBooking::create([
        'room_booking_id' => $rb->id,
        'park_id'         => $park->id,
        'date'            => now()->toDateString(), // today — cancellation disallowed
        'guests'          => $rb->guests,
        'status'          => 'confirmed',
        'price_per_guest' => $park->price,
        'total_price'     => (float) $park->price * $rb->guests,
    ]);

    $this->actingAs($customer)
        ->deleteJson("/api/park-bookings/{$booking->id}")
        ->assertForbidden();
});

test('park-manager can cancel any park booking', function () {
    [, $type]       = seedRoomSetup();
    $customer       = parkCustomer();
    [$rb, $checkIn] = bookedStayFor($customer, $type);
    $park           = openEveryDayPark();
    $manager        = parkManagerUser();

    $booking = ParkBooking::create([
        'room_booking_id' => $rb->id,
        'park_id'         => $park->id,
        'date'            => $checkIn,
        'guests'          => $rb->guests,
        'status'          => 'confirmed',
        'price_per_guest' => $park->price,
        'total_price'     => (float) $park->price * $rb->guests,
    ]);

    $this->actingAs($manager)
        ->patchJson("/api/park-bookings/{$booking->id}", ['status' => 'cancelled'])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');
});

test('superadmin sees every park booking', function () {
    [, $typeA]        = seedRoomSetup();
    [, $typeB]        = seedRoomSetup();
    $c1               = parkCustomer();
    $c2               = parkCustomer();
    [$rbA, $checkInA] = bookedStayFor($c1, $typeA);
    [$rbB, $checkInB] = bookedStayFor($c2, $typeB);
    $park             = openEveryDayPark();

    foreach ([[$rbA, $checkInA], [$rbB, $checkInB]] as [$rb, $date]) {
        ParkBooking::create([
            'room_booking_id' => $rb->id,
            'park_id'         => $park->id,
            'date'            => $date,
            'guests'          => $rb->guests,
            'status'          => 'confirmed',
            'price_per_guest' => $park->price,
            'total_price'     => (float) $park->price * $rb->guests,
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
    [, $typeA]        = seedRoomSetup();
    [, $typeB]        = seedRoomSetup();
    $me               = parkCustomer();
    $other            = parkCustomer();
    [$rbMine, $dMine] = bookedStayFor($me, $typeA);
    [$rbTheirs, $dTheirs] = bookedStayFor($other, $typeB);
    $park             = openEveryDayPark();

    ParkBooking::create([
        'room_booking_id' => $rbMine->id,
        'park_id'         => $park->id,
        'date'            => $dMine,
        'guests'          => $rbMine->guests,
        'status'          => 'confirmed',
        'price_per_guest' => $park->price,
        'total_price'     => (float) $park->price * $rbMine->guests,
    ]);
    ParkBooking::create([
        'room_booking_id' => $rbTheirs->id,
        'park_id'         => $park->id,
        'date'            => $dTheirs,
        'guests'          => $rbTheirs->guests,
        'status'          => 'confirmed',
        'price_per_guest' => $park->price,
        'total_price'     => (float) $park->price * $rbTheirs->guests,
    ]);

    $this->actingAs($me)
        ->getJson('/api/park-bookings')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.room_booking_id', $rbMine->id);
});
