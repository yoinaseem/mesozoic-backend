<?php

use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\RoomBooking;
use App\Models\RoomType;
use App\Models\User;

function hotelWithType(int $roomCount = 2, int $capacity = 2, int $price = 100): array
{
    $hotel = Hotel::factory()->create();
    $type  = $hotel->roomTypes()->create([
        'name'     => 'Standard',
        'capacity' => $capacity,
        'price'    => $price,
    ]);

    for ($i = 1; $i <= $roomCount; $i++) {
        $hotel->rooms()->create([
            'room_type_id' => $type->id,
            'room_no'      => (string) (100 + $i),
        ]);
    }

    return [$hotel, $type];
}

function customerUser(): User
{
    $u = User::factory()->create();
    $u->assignRole('customer');

    return $u;
}

function managerOf(Hotel $hotel): User
{
    $u = User::factory()->create();
    $u->assignRole('hotel-manager');
    $u->managedHotels()->attach($hotel);

    return $u;
}

function confirmedBooking(
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
        'nights'          => \Carbon\Carbon::parse($checkIn)->diffInDays(\Carbon\Carbon::parse($checkOut)),
        'total_price'     => $type->price * \Carbon\Carbon::parse($checkIn)->diffInDays(\Carbon\Carbon::parse($checkOut)),
    ]);
}

test('unauthenticated request cannot list bookings', function () {
    $this->getJson('/api/room-bookings')->assertUnauthorized();
});

test('customer creates a booking with no reservation id, auto-creates reservation', function () {
    [, $type]  = hotelWithType();
    $customer  = customerUser();
    $checkIn   = now()->addDays(3)->toDateString();
    $checkOut  = now()->addDays(5)->toDateString();

    $response = $this->actingAs($customer)->postJson('/api/room-bookings', [
        'room_type_id'   => $type->id,
        'check_in_date'  => $checkIn,
        'check_out_date' => $checkOut,
        'guests'         => 2,
    ])->assertCreated();

    $reservationId = $response->json('data.reservation_id');

    expect($reservationId)->not->toBeNull();
    expect(Reservation::find($reservationId)->user_id)->toBe($customer->id);

    $response
        ->assertJsonPath('data.status', 'confirmed')
        ->assertJsonPath('data.nights', 2)
        ->assertJsonPath('data.total_price', '200.00')
        ->assertJsonPath('data.hotel_id', $type->hotel_id);
});

test('customer attaches a second booking to the same reservation', function () {
    [, $typeA] = hotelWithType(2, 2, 100);
    [, $typeB] = hotelWithType(2, 3, 150);
    $customer  = customerUser();

    $first = $this->actingAs($customer)->postJson('/api/room-bookings', [
        'room_type_id'   => $typeA->id,
        'check_in_date'  => now()->addDays(3)->toDateString(),
        'check_out_date' => now()->addDays(5)->toDateString(),
        'guests'         => 2,
    ])->assertCreated();

    $reservationId = $first->json('data.reservation_id');

    $this->actingAs($customer)->postJson('/api/room-bookings', [
        'reservation_id' => $reservationId,
        'room_type_id'   => $typeB->id,
        'check_in_date'  => now()->addDays(4)->toDateString(),
        'check_out_date' => now()->addDays(7)->toDateString(),
        'guests'         => 3,
    ])
        ->assertCreated()
        ->assertJsonPath('data.reservation_id', $reservationId);

    expect(RoomBooking::where('reservation_id', $reservationId)->count())->toBe(2);
});

test('customer cannot attach a booking to another users reservation', function () {
    [, $type]  = hotelWithType();
    $owner     = customerUser();
    $outsider  = customerUser();
    $reservation = Reservation::create(['user_id' => $owner->id]);

    $this->actingAs($outsider)->postJson('/api/room-bookings', [
        'reservation_id' => $reservation->id,
        'room_type_id'   => $type->id,
        'check_in_date'  => now()->addDays(3)->toDateString(),
        'check_out_date' => now()->addDays(5)->toDateString(),
        'guests'         => 2,
    ])->assertUnprocessable()->assertJsonValidationErrors(['reservation_id']);
});

test('checkout must be after checkin', function () {
    [, $type] = hotelWithType();
    $customer = customerUser();
    $date     = now()->addDays(3)->toDateString();

    $this->actingAs($customer)->postJson('/api/room-bookings', [
        'room_type_id'   => $type->id,
        'check_in_date'  => $date,
        'check_out_date' => $date,
        'guests'         => 2,
    ])->assertUnprocessable()->assertJsonValidationErrors(['check_out_date']);
});

test('guests cannot exceed room-type capacity', function () {
    [, $type] = hotelWithType(roomCount: 2, capacity: 2);
    $customer = customerUser();

    $this->actingAs($customer)->postJson('/api/room-bookings', [
        'room_type_id'   => $type->id,
        'check_in_date'  => now()->addDays(3)->toDateString(),
        'check_out_date' => now()->addDays(5)->toDateString(),
        'guests'         => 3,
    ])->assertUnprocessable()->assertJsonValidationErrors(['guests']);
});

test('booking rejected when all rooms of type are confirmed-booked for the dates', function () {
    [, $type]  = hotelWithType(roomCount: 2);
    $customer  = customerUser();
    $checkIn   = now()->addDays(3)->toDateString();
    $checkOut  = now()->addDays(5)->toDateString();

    // Seed 2 confirmed bookings that fully occupy the room type.
    $r1 = Reservation::create(['user_id' => customerUser()->id]);
    $r2 = Reservation::create(['user_id' => customerUser()->id]);
    confirmedBooking($r1, $type, $checkIn, $checkOut);
    confirmedBooking($r2, $type, $checkIn, $checkOut);

    $this->actingAs($customer)->postJson('/api/room-bookings', [
        'room_type_id'   => $type->id,
        'check_in_date'  => $checkIn,
        'check_out_date' => $checkOut,
        'guests'         => 2,
    ])->assertUnprocessable()->assertJsonValidationErrors(['room_type_id']);
});

test('cancelled bookings do not block new ones on the same dates', function () {
    [, $type]  = hotelWithType(roomCount: 1);
    $customer  = customerUser();
    $checkIn   = now()->addDays(3)->toDateString();
    $checkOut  = now()->addDays(5)->toDateString();

    $otherRes = Reservation::create(['user_id' => customerUser()->id]);
    confirmedBooking($otherRes, $type, $checkIn, $checkOut, status: 'cancelled');

    $this->actingAs($customer)->postJson('/api/room-bookings', [
        'room_type_id'   => $type->id,
        'check_in_date'  => $checkIn,
        'check_out_date' => $checkOut,
        'guests'         => 2,
    ])->assertCreated();
});

test('abutting dates are allowed (exclusive checkout)', function () {
    [, $type] = hotelWithType(roomCount: 1);
    $customer = customerUser();
    $day1 = now()->addDays(3)->toDateString();
    $day3 = now()->addDays(5)->toDateString();
    $day5 = now()->addDays(7)->toDateString();

    $otherRes = Reservation::create(['user_id' => customerUser()->id]);
    confirmedBooking($otherRes, $type, $day1, $day3);

    $this->actingAs($customer)->postJson('/api/room-bookings', [
        'room_type_id'   => $type->id,
        'check_in_date'  => $day3,
        'check_out_date' => $day5,
        'guests'         => 2,
    ])->assertCreated();
});

test('overnight booking today to tomorrow works', function () {
    [, $type] = hotelWithType();
    $customer = customerUser();

    $this->actingAs($customer)->postJson('/api/room-bookings', [
        'room_type_id'   => $type->id,
        'check_in_date'  => now()->toDateString(),
        'check_out_date' => now()->addDay()->toDateString(),
        'guests'         => 2,
    ])
        ->assertCreated()
        ->assertJsonPath('data.nights', 1);
});

test('customer cannot update a room booking', function () {
    [, $type]   = hotelWithType();
    $customer   = customerUser();
    $reservation = Reservation::create(['user_id' => $customer->id]);
    $booking    = confirmedBooking(
        $reservation,
        $type,
        now()->addDays(3)->toDateString(),
        now()->addDays(5)->toDateString(),
    );

    $this->actingAs($customer)
        ->patchJson("/api/room-bookings/{$booking->id}", ['status' => 'cancelled'])
        ->assertForbidden();
});

test('customer cannot view another customers booking', function () {
    [, $type] = hotelWithType();
    $owner    = customerUser();
    $stranger = customerUser();
    $reservation = Reservation::create(['user_id' => $owner->id]);
    $booking  = confirmedBooking(
        $reservation,
        $type,
        now()->addDays(3)->toDateString(),
        now()->addDays(5)->toDateString(),
    );

    $this->actingAs($stranger)
        ->getJson("/api/room-bookings/{$booking->id}")
        ->assertForbidden();
});

test('customer cancels own booking before check-in and row persists', function () {
    [, $type]   = hotelWithType();
    $customer   = customerUser();
    $reservation = Reservation::create(['user_id' => $customer->id]);
    $booking    = confirmedBooking(
        $reservation,
        $type,
        now()->addDays(3)->toDateString(),
        now()->addDays(5)->toDateString(),
    );

    $this->actingAs($customer)
        ->deleteJson("/api/room-bookings/{$booking->id}")
        ->assertNoContent();

    $booking->refresh();
    expect($booking->status)->toBe('cancelled');
    expect($booking->cancelled_at)->not->toBeNull();
});

test('customer cannot cancel own booking after check-in has started', function () {
    [, $type]   = hotelWithType();
    $customer   = customerUser();
    $reservation = Reservation::create(['user_id' => $customer->id]);
    $booking    = confirmedBooking(
        $reservation,
        $type,
        now()->subDay()->toDateString(),
        now()->addDays(2)->toDateString(),
    );

    $this->actingAs($customer)
        ->deleteJson("/api/room-bookings/{$booking->id}")
        ->assertForbidden();
});

test('hotel-manager can cancel a booking in their hotel', function () {
    [$hotel, $type] = hotelWithType();
    $manager = managerOf($hotel);
    $guest   = customerUser();
    $reservation = Reservation::create(['user_id' => $guest->id]);
    $booking = confirmedBooking(
        $reservation,
        $type,
        now()->addDays(3)->toDateString(),
        now()->addDays(5)->toDateString(),
    );

    $this->actingAs($manager)
        ->patchJson("/api/room-bookings/{$booking->id}", ['status' => 'cancelled'])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');
});

test('hotel-manager can assign a specific room matching hotel and type', function () {
    [$hotel, $type] = hotelWithType();
    $manager = managerOf($hotel);
    $room    = $hotel->rooms()->where('room_type_id', $type->id)->first();
    $guest   = customerUser();
    $reservation = Reservation::create(['user_id' => $guest->id]);
    $booking = confirmedBooking(
        $reservation,
        $type,
        now()->addDays(3)->toDateString(),
        now()->addDays(5)->toDateString(),
    );

    $this->actingAs($manager)
        ->patchJson("/api/room-bookings/{$booking->id}", ['room_id' => $room->id])
        ->assertOk()
        ->assertJsonPath('data.room_id', $room->id);
});

test('hotel-manager cannot assign a room from a different hotel', function () {
    [$hotelA, $typeA] = hotelWithType();
    [$hotelB]         = hotelWithType();
    $manager = managerOf($hotelA);
    $foreignRoom = $hotelB->rooms()->first();
    $guest   = customerUser();
    $reservation = Reservation::create(['user_id' => $guest->id]);
    $booking = confirmedBooking(
        $reservation,
        $typeA,
        now()->addDays(3)->toDateString(),
        now()->addDays(5)->toDateString(),
    );

    $this->actingAs($manager)
        ->patchJson("/api/room-bookings/{$booking->id}", ['room_id' => $foreignRoom->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['room_id']);
});

test('hotel-manager cannot update a booking in an unrelated hotel', function () {
    [$owned]         = hotelWithType();
    [, $foreignType] = hotelWithType();
    $manager = managerOf($owned);
    $guest   = customerUser();
    $reservation = Reservation::create(['user_id' => $guest->id]);
    $booking = confirmedBooking(
        $reservation,
        $foreignType,
        now()->addDays(3)->toDateString(),
        now()->addDays(5)->toDateString(),
    );

    $this->actingAs($manager)
        ->patchJson("/api/room-bookings/{$booking->id}", ['status' => 'cancelled'])
        ->assertForbidden();
});

test('superadmin sees every booking', function () {
    [, $typeA] = hotelWithType();
    [, $typeB] = hotelWithType();
    $c1 = customerUser();
    $c2 = customerUser();
    $r1 = Reservation::create(['user_id' => $c1->id]);
    $r2 = Reservation::create(['user_id' => $c2->id]);
    confirmedBooking($r1, $typeA, now()->addDays(3)->toDateString(), now()->addDays(5)->toDateString());
    confirmedBooking($r2, $typeB, now()->addDays(6)->toDateString(), now()->addDays(8)->toDateString());

    $admin = User::factory()->create();
    $admin->assignRole('superadmin');

    $this->actingAs($admin)
        ->getJson('/api/room-bookings')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('booking creation auto-assigns a specific room of the requested type', function () {
    [$hotel, $type] = hotelWithType(roomCount: 2);
    $customer = customerUser();

    $response = $this->actingAs($customer)->postJson('/api/room-bookings', [
        'room_type_id'   => $type->id,
        'check_in_date'  => now()->addDays(3)->toDateString(),
        'check_out_date' => now()->addDays(5)->toDateString(),
        'guests'         => 2,
    ])->assertCreated();

    $assignedRoomId = $response->json('data.room_id');
    $hotelRoomIds   = $hotel->rooms()->pluck('id')->all();

    expect($assignedRoomId)->not->toBeNull();
    expect($hotelRoomIds)->toContain($assignedRoomId);
});

test('two overlapping bookings pick different rooms', function () {
    [, $type] = hotelWithType(roomCount: 2);

    $a = $this->actingAs(customerUser())->postJson('/api/room-bookings', [
        'room_type_id'   => $type->id,
        'check_in_date'  => now()->addDays(3)->toDateString(),
        'check_out_date' => now()->addDays(5)->toDateString(),
        'guests'         => 2,
    ])->assertCreated();

    $b = $this->actingAs(customerUser())->postJson('/api/room-bookings', [
        'room_type_id'   => $type->id,
        'check_in_date'  => now()->addDays(3)->toDateString(),
        'check_out_date' => now()->addDays(5)->toDateString(),
        'guests'         => 2,
    ])->assertCreated();

    expect($a->json('data.room_id'))->not->toBe($b->json('data.room_id'));
});

test('explicit room reassignment to an already-occupied room fails', function () {
    [$hotel, $type] = hotelWithType(roomCount: 2);
    $customer = customerUser();
    $manager  = managerOf($hotel);
    $dates    = [now()->addDays(3)->toDateString(), now()->addDays(5)->toDateString()];

    $first = $this->actingAs($customer)->postJson('/api/room-bookings', [
        'room_type_id'   => $type->id,
        'check_in_date'  => $dates[0],
        'check_out_date' => $dates[1],
        'guests'         => 2,
    ])->assertCreated();

    $second = $this->actingAs($customer)->postJson('/api/room-bookings', [
        'room_type_id'   => $type->id,
        'check_in_date'  => $dates[0],
        'check_out_date' => $dates[1],
        'guests'         => 2,
    ])->assertCreated();

    // Try to steal the first booking's room for the second booking.
    $this->actingAs($manager)
        ->patchJson("/api/room-bookings/{$second->json('data.id')}", [
            'room_id' => $first->json('data.room_id'),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['room_id']);
});

test('date-change keeps the current room when still free on new dates', function () {
    [, $type] = hotelWithType(roomCount: 2);
    $customer = customerUser();
    $manager  = managerOf($type->hotel);

    $booking = $this->actingAs($customer)->postJson('/api/room-bookings', [
        'room_type_id'   => $type->id,
        'check_in_date'  => now()->addDays(3)->toDateString(),
        'check_out_date' => now()->addDays(5)->toDateString(),
        'guests'         => 2,
    ])->assertCreated();

    $originalRoomId = $booking->json('data.room_id');

    $this->actingAs($manager)
        ->patchJson("/api/room-bookings/{$booking->json('data.id')}", [
            'check_in_date'  => now()->addDays(10)->toDateString(),
            'check_out_date' => now()->addDays(12)->toDateString(),
        ])
        ->assertOk()
        ->assertJsonPath('data.room_id', $originalRoomId);
});

test('date-change auto-reassigns when current room is taken on the new dates', function () {
    [, $type] = hotelWithType(roomCount: 2);
    $manager  = managerOf($type->hotel);

    // Booking A on days 10-12 (gets room #1).
    $a = $this->actingAs(customerUser())->postJson('/api/room-bookings', [
        'room_type_id'   => $type->id,
        'check_in_date'  => now()->addDays(10)->toDateString(),
        'check_out_date' => now()->addDays(12)->toDateString(),
        'guests'         => 2,
    ])->assertCreated();

    // Booking B on days 3-5 (gets room #1 too — no overlap with A).
    $b = $this->actingAs(customerUser())->postJson('/api/room-bookings', [
        'room_type_id'   => $type->id,
        'check_in_date'  => now()->addDays(3)->toDateString(),
        'check_out_date' => now()->addDays(5)->toDateString(),
        'guests'         => 2,
    ])->assertCreated();

    expect($a->json('data.room_id'))->toBe($b->json('data.room_id'));

    // Move B into A's window. B's current room is now taken → auto-reassign.
    $response = $this->actingAs($manager)
        ->patchJson("/api/room-bookings/{$b->json('data.id')}", [
            'check_in_date'  => now()->addDays(10)->toDateString(),
            'check_out_date' => now()->addDays(12)->toDateString(),
        ])
        ->assertOk();

    expect($response->json('data.room_id'))->not->toBe($a->json('data.room_id'));
    expect($response->json('data.room_id'))->not->toBeNull();
});

test('date-change on update rejects conflicts with another confirmed booking', function () {
    [, $type] = hotelWithType(roomCount: 1);
    $customer = customerUser();
    $manager  = managerOf($type->hotel);

    $r1 = Reservation::create(['user_id' => $customer->id]);
    $r2 = Reservation::create(['user_id' => customerUser()->id]);

    $target = confirmedBooking(
        $r1,
        $type,
        now()->addDays(10)->toDateString(),
        now()->addDays(12)->toDateString(),
    );
    confirmedBooking(
        $r2,
        $type,
        now()->addDays(3)->toDateString(),
        now()->addDays(5)->toDateString(),
    );

    $this->actingAs($manager)
        ->patchJson("/api/room-bookings/{$target->id}", [
            'check_in_date'  => now()->addDays(3)->toDateString(),
            'check_out_date' => now()->addDays(5)->toDateString(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['room_type_id']);
});
