<?php

use App\Models\Ferry;
use App\Models\FerryBooking;
use App\Models\FerrySchedule;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\RoomBooking;
use App\Models\RoomType;
use App\Models\User;
use Carbon\Carbon;

function fbSeedRoomType(int $capacity = 4, int $price = 100): RoomType
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

function fbCustomer(): User
{
    $u = User::factory()->create();
    $u->assignRole('customer');

    return $u;
}

function fbFerryManager(): User
{
    $u = User::factory()->create();
    $u->assignRole('ferry-manager');

    return $u;
}

function fbConfirmedRoomBooking(
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

function fbFerry(int $capacity = 50, float $price = 40.0): Ferry
{
    return Ferry::create([
        'name' => 'Isla Express',
        'description' => 'Mainland → island shuttle',
        'price' => $price,
        'capacity' => $capacity,
        'image' => null,
    ]);
}

function fbSchedule(
    Ferry $ferry,
    string $travelDate,
    string $departureTime = '09:00:00',
    string $departurePort = 'Mainland',
    string $arrivalPort = 'Isla Nublar',
    string $status = 'scheduled',
): FerrySchedule {
    return $ferry->schedules()->create([
        'travel_date' => $travelDate,
        'departure_time' => $departureTime,
        'arrival_date' => $travelDate,
        'arrival_time' => '11:00:00',
        'departure_port' => $departurePort,
        'arrival_port' => $arrivalPort,
        'status' => $status,
    ]);
}

/**
 * 1-room reservation, 3-night stay starting +3 days so +3, +4, +5 are the
 * on-island days, +3 is check-in (bookable for arrival ferry), +6 is
 * check-out (bookable for departure ferry under the inclusive rule).
 */
function fbSingleRoomReservation(User $customer, int $guests = 2): array
{
    $type = fbSeedRoomType();
    $reservation = Reservation::create(['user_id' => $customer->id]);
    $checkIn = now()->addDays(3)->toDateString();
    $checkOut = now()->addDays(6)->toDateString();
    fbConfirmedRoomBooking($reservation, $type, $checkIn, $checkOut, $guests);

    return [$reservation, $checkIn, $checkOut];
}

test('unauthenticated request cannot list ferry bookings', function () {
    $this->getJson('/api/ferry-bookings')->assertUnauthorized();
});

test('customer books the arrival ferry on check-in day', function () {
    $customer = fbCustomer();
    [$reservation, $checkIn] = fbSingleRoomReservation($customer, guests: 2);
    $schedule = fbSchedule(fbFerry(price: 40.0), $checkIn);

    $this->actingAs($customer)->postJson('/api/ferry-bookings', [
        'reservation_id' => $reservation->id,
        'ferry_schedule_id' => $schedule->id,
        'guests' => 2,
    ])
        ->assertCreated()
        ->assertJsonPath('data.reservation_id', $reservation->id)
        ->assertJsonPath('data.ferry_schedule_id', $schedule->id)
        ->assertJsonPath('data.guests', 2)
        ->assertJsonPath('data.status', 'confirmed')
        ->assertJsonPath('data.total_price', '80.00'); // 2 × $40
});

test('customer books the departure ferry on check-out day (inclusive window)', function () {
    $customer = fbCustomer();
    [$reservation, , $checkOut] = fbSingleRoomReservation($customer, guests: 2);
    $schedule = fbSchedule(fbFerry(), $checkOut, departureTime: '16:00:00', departurePort: 'Isla Nublar', arrivalPort: 'Mainland');

    $this->actingAs($customer)->postJson('/api/ferry-bookings', [
        'reservation_id' => $reservation->id,
        'ferry_schedule_id' => $schedule->id,
        'guests' => 2,
    ])->assertCreated();
});

test('customer books a mid-stay day-trip ferry', function () {
    $customer = fbCustomer();
    [$reservation, $checkIn] = fbSingleRoomReservation($customer, guests: 2);
    $midDay = Carbon::parse($checkIn)->addDay()->toDateString();
    $schedule = fbSchedule(fbFerry(), $midDay);

    $this->actingAs($customer)->postJson('/api/ferry-bookings', [
        'reservation_id' => $reservation->id,
        'ferry_schedule_id' => $schedule->id,
        'guests' => 2,
    ])->assertCreated();
});

test('booking a ferry before check-in is rejected', function () {
    $customer = fbCustomer();
    [$reservation, $checkIn] = fbSingleRoomReservation($customer);
    $before = Carbon::parse($checkIn)->subDay()->toDateString();
    $schedule = fbSchedule(fbFerry(), $before);

    $this->actingAs($customer)->postJson('/api/ferry-bookings', [
        'reservation_id' => $reservation->id,
        'ferry_schedule_id' => $schedule->id,
        'guests' => 2,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['ferry_schedule_id']);
});

test('booking a ferry after check-out is rejected', function () {
    $customer = fbCustomer();
    [$reservation, , $checkOut] = fbSingleRoomReservation($customer);
    $after = Carbon::parse($checkOut)->addDay()->toDateString();
    $schedule = fbSchedule(fbFerry(), $after);

    $this->actingAs($customer)->postJson('/api/ferry-bookings', [
        'reservation_id' => $reservation->id,
        'ferry_schedule_id' => $schedule->id,
        'guests' => 2,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['ferry_schedule_id']);
});

test('guests cannot exceed the reservation room-booking guests', function () {
    $customer = fbCustomer();
    [$reservation, $checkIn] = fbSingleRoomReservation($customer, guests: 2);
    $schedule = fbSchedule(fbFerry(), $checkIn);

    $this->actingAs($customer)->postJson('/api/ferry-bookings', [
        'reservation_id' => $reservation->id,
        'ferry_schedule_id' => $schedule->id,
        'guests' => 3,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['guests']);
});

test('ferry capacity cap reports no available space', function () {
    $customer = fbCustomer();
    [$reservation, $checkIn] = fbSingleRoomReservation($customer, guests: 2);
    $ferry = fbFerry(capacity: 3); // only 3 seats on the vessel
    $schedule = fbSchedule($ferry, $checkIn);

    // Pre-fill 2 of 3 seats via another customer.
    $other = fbCustomer();
    [$otherRes] = fbSingleRoomReservation($other, guests: 2);
    FerryBooking::create([
        'reservation_id' => $otherRes->id,
        'ferry_schedule_id' => $schedule->id,
        'guests' => 2,
        'status' => 'confirmed',
        'price_per_guest' => $ferry->price,
        'total_price' => (float) $ferry->price * 2,
    ]);

    $this->actingAs($customer)->postJson('/api/ferry-bookings', [
        'reservation_id' => $reservation->id,
        'ferry_schedule_id' => $schedule->id,
        'guests' => 2,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['ferry_schedule_id'])
        ->assertJsonFragment(['ferry_schedule_id' => ['There is no available space on this ferry.']]);
});

test('booking a cancelled ferry schedule is rejected', function () {
    $customer = fbCustomer();
    [$reservation, $checkIn] = fbSingleRoomReservation($customer);
    $schedule = fbSchedule(fbFerry(), $checkIn, status: 'cancelled');

    $this->actingAs($customer)->postJson('/api/ferry-bookings', [
        'reservation_id' => $reservation->id,
        'ferry_schedule_id' => $schedule->id,
        'guests' => 2,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['ferry_schedule_id']);
});

test('booking a completed ferry schedule is rejected', function () {
    $customer = fbCustomer();
    [$reservation, $checkIn] = fbSingleRoomReservation($customer);
    $schedule = fbSchedule(fbFerry(), $checkIn, status: 'completed');

    $this->actingAs($customer)->postJson('/api/ferry-bookings', [
        'reservation_id' => $reservation->id,
        'ferry_schedule_id' => $schedule->id,
        'guests' => 2,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['ferry_schedule_id']);
});

test('duplicate (reservation, schedule) rejected among confirmed rows', function () {
    $customer = fbCustomer();
    [$reservation, $checkIn] = fbSingleRoomReservation($customer);
    $schedule = fbSchedule(fbFerry(), $checkIn);

    $this->actingAs($customer)->postJson('/api/ferry-bookings', [
        'reservation_id' => $reservation->id,
        'ferry_schedule_id' => $schedule->id,
        'guests' => 2,
    ])->assertCreated();

    $this->actingAs($customer)->postJson('/api/ferry-bookings', [
        'reservation_id' => $reservation->id,
        'ferry_schedule_id' => $schedule->id,
        'guests' => 2,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['ferry_schedule_id']);
});

test('same-day round trip on two different departures is allowed', function () {
    $customer = fbCustomer();
    [$reservation, $checkIn] = fbSingleRoomReservation($customer);
    $ferry = fbFerry();
    $outbound = fbSchedule($ferry, $checkIn, departureTime: '09:00:00');
    $inbound = fbSchedule($ferry, $checkIn, departureTime: '17:00:00', departurePort: 'Isla Nublar', arrivalPort: 'Mainland');

    $this->actingAs($customer)->postJson('/api/ferry-bookings', [
        'reservation_id' => $reservation->id,
        'ferry_schedule_id' => $outbound->id,
        'guests' => 2,
    ])->assertCreated();

    $this->actingAs($customer)->postJson('/api/ferry-bookings', [
        'reservation_id' => $reservation->id,
        'ferry_schedule_id' => $inbound->id,
        'guests' => 2,
    ])->assertCreated();
});

test('reservation with only cancelled rooms has empty seat pool → rejected', function () {
    $customer = fbCustomer();
    $type = fbSeedRoomType();
    $reservation = Reservation::create(['user_id' => $customer->id]);
    $checkIn = now()->addDays(3)->toDateString();
    fbConfirmedRoomBooking(
        $reservation,
        $type,
        $checkIn,
        now()->addDays(6)->toDateString(),
        status: 'cancelled',
    );
    $schedule = fbSchedule(fbFerry(), $checkIn);

    $this->actingAs($customer)->postJson('/api/ferry-bookings', [
        'reservation_id' => $reservation->id,
        'ferry_schedule_id' => $schedule->id,
        'guests' => 1,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['ferry_schedule_id']);
});

test('customer cannot attach a ferry booking to another users reservation', function () {
    $owner = fbCustomer();
    $stranger = fbCustomer();
    [$reservation, $checkIn] = fbSingleRoomReservation($owner);
    $schedule = fbSchedule(fbFerry(), $checkIn);

    $this->actingAs($stranger)->postJson('/api/ferry-bookings', [
        'reservation_id' => $reservation->id,
        'ferry_schedule_id' => $schedule->id,
        'guests' => 2,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reservation_id']);
});

test('customer cannot update a ferry booking', function () {
    $customer = fbCustomer();
    [$reservation, $checkIn] = fbSingleRoomReservation($customer);
    $schedule = fbSchedule(fbFerry(), $checkIn);

    $booking = FerryBooking::create([
        'reservation_id' => $reservation->id,
        'ferry_schedule_id' => $schedule->id,
        'guests' => 2,
        'status' => 'confirmed',
        'price_per_guest' => $schedule->ferry->price,
        'total_price' => (float) $schedule->ferry->price * 2,
    ]);

    $this->actingAs($customer)
        ->patchJson("/api/ferry-bookings/{$booking->id}", ['status' => 'cancelled'])
        ->assertForbidden();
});

test('customer cannot cancel their own ferry booking (staff only)', function () {
    $customer = fbCustomer();
    [$reservation, $checkIn] = fbSingleRoomReservation($customer);
    $schedule = fbSchedule(fbFerry(), $checkIn);

    $booking = FerryBooking::create([
        'reservation_id' => $reservation->id,
        'ferry_schedule_id' => $schedule->id,
        'guests' => 2,
        'status' => 'confirmed',
        'price_per_guest' => $schedule->ferry->price,
        'total_price' => (float) $schedule->ferry->price * 2,
    ]);

    $this->actingAs($customer)
        ->deleteJson("/api/ferry-bookings/{$booking->id}")
        ->assertForbidden();
});

test('customer cannot view another customers ferry booking', function () {
    $owner = fbCustomer();
    $stranger = fbCustomer();
    [$reservation, $checkIn] = fbSingleRoomReservation($owner);
    $schedule = fbSchedule(fbFerry(), $checkIn);

    $booking = FerryBooking::create([
        'reservation_id' => $reservation->id,
        'ferry_schedule_id' => $schedule->id,
        'guests' => 2,
        'status' => 'confirmed',
        'price_per_guest' => $schedule->ferry->price,
        'total_price' => (float) $schedule->ferry->price * 2,
    ]);

    $this->actingAs($stranger)
        ->getJson("/api/ferry-bookings/{$booking->id}")
        ->assertForbidden();
});

test('ferry-manager can cancel any ferry booking', function () {
    $customer = fbCustomer();
    [$reservation, $checkIn] = fbSingleRoomReservation($customer);
    $schedule = fbSchedule(fbFerry(), $checkIn);
    $manager = fbFerryManager();

    $booking = FerryBooking::create([
        'reservation_id' => $reservation->id,
        'ferry_schedule_id' => $schedule->id,
        'guests' => 2,
        'status' => 'confirmed',
        'price_per_guest' => $schedule->ferry->price,
        'total_price' => (float) $schedule->ferry->price * 2,
    ]);

    $this->actingAs($manager)
        ->deleteJson("/api/ferry-bookings/{$booking->id}")
        ->assertNoContent();

    $booking->refresh();
    expect($booking->status)->toBe('cancelled');
    expect($booking->cancelled_at)->not->toBeNull();
});

test('ferry-manager can update booking status', function () {
    $customer = fbCustomer();
    [$reservation, $checkIn] = fbSingleRoomReservation($customer);
    $schedule = fbSchedule(fbFerry(), $checkIn);
    $manager = fbFerryManager();

    $booking = FerryBooking::create([
        'reservation_id' => $reservation->id,
        'ferry_schedule_id' => $schedule->id,
        'guests' => 2,
        'status' => 'confirmed',
        'price_per_guest' => $schedule->ferry->price,
        'total_price' => (float) $schedule->ferry->price * 2,
    ]);

    $this->actingAs($manager)
        ->patchJson("/api/ferry-bookings/{$booking->id}", ['status' => 'cancelled'])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');
});

test('superadmin sees every ferry booking', function () {
    [$rA, $dA] = fbSingleRoomReservation(fbCustomer());
    [$rB, $dB] = fbSingleRoomReservation(fbCustomer());
    $ferry = fbFerry();
    $sA = fbSchedule($ferry, $dA);
    $sB = fbSchedule($ferry, $dB, departureTime: '15:00:00');

    foreach ([[$rA, $sA], [$rB, $sB]] as [$r, $s]) {
        FerryBooking::create([
            'reservation_id' => $r->id,
            'ferry_schedule_id' => $s->id,
            'guests' => 2,
            'status' => 'confirmed',
            'price_per_guest' => $ferry->price,
            'total_price' => (float) $ferry->price * 2,
        ]);
    }

    $admin = User::factory()->create();
    $admin->assignRole('superadmin');

    $this->actingAs($admin)
        ->getJson('/api/ferry-bookings')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('customer index only returns their own ferry bookings', function () {
    $me = fbCustomer();
    $other = fbCustomer();
    [$rMine, $dMine] = fbSingleRoomReservation($me);
    [$rTheirs, $dTheirs] = fbSingleRoomReservation($other);
    $ferry = fbFerry();
    $sMine = fbSchedule($ferry, $dMine);
    $sTheirs = fbSchedule($ferry, $dTheirs, departureTime: '15:00:00');

    FerryBooking::create([
        'reservation_id' => $rMine->id,
        'ferry_schedule_id' => $sMine->id,
        'guests' => 2,
        'status' => 'confirmed',
        'price_per_guest' => $ferry->price,
        'total_price' => (float) $ferry->price * 2,
    ]);
    FerryBooking::create([
        'reservation_id' => $rTheirs->id,
        'ferry_schedule_id' => $sTheirs->id,
        'guests' => 2,
        'status' => 'confirmed',
        'price_per_guest' => $ferry->price,
        'total_price' => (float) $ferry->price * 2,
    ]);

    $this->actingAs($me)
        ->getJson('/api/ferry-bookings')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.reservation_id', $rMine->id);
});
