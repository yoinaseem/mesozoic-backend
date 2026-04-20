<?php

use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\RoomBooking;
use App\Models\RoomType;
use App\Models\User;

function seedHotelAndType(int $roomCount = 2, int $capacity = 2, int $price = 100): array
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

function makeBooking(
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

function customerActor(): User
{
    $u = User::factory()->create();
    $u->assignRole('customer');

    return $u;
}

test('customer sees only their own reservations', function () {
    [, $type] = seedHotelAndType();
    $me       = customerActor();
    $stranger = customerActor();

    $mine     = Reservation::create(['user_id' => $me->id]);
    $theirs   = Reservation::create(['user_id' => $stranger->id]);
    makeBooking($mine,   $type, '2026-05-01', '2026-05-03');
    makeBooking($theirs, $type, '2026-05-04', '2026-05-06');

    $this->actingAs($me)
        ->getJson('/api/reservations')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mine->id);
});

test('customer can show their own reservation with nested room bookings', function () {
    [, $type]   = seedHotelAndType();
    $me         = customerActor();
    $reservation = Reservation::create(['user_id' => $me->id]);
    makeBooking($reservation, $type, '2026-05-01', '2026-05-03');

    $this->actingAs($me)
        ->getJson("/api/reservations/{$reservation->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data.room_bookings');
});

test('customer cannot view another customer\'s reservation', function () {
    [, $type] = seedHotelAndType();
    $owner    = customerActor();
    $stranger = customerActor();
    $reservation = Reservation::create(['user_id' => $owner->id]);
    makeBooking($reservation, $type, '2026-05-01', '2026-05-03');

    $this->actingAs($stranger)
        ->getJson("/api/reservations/{$reservation->id}")
        ->assertForbidden();
});

test('hotel-manager sees reservations that touch one of their hotels', function () {
    [$ownedHotel, $ownedType]   = seedHotelAndType();
    [, $foreignType]            = seedHotelAndType();

    $manager = User::factory()->create();
    $manager->assignRole('hotel-manager');
    $manager->managedHotels()->attach($ownedHotel);

    $mixedCustomer = customerActor();
    $otherCustomer = customerActor();

    $inScope    = Reservation::create(['user_id' => $mixedCustomer->id]);
    $outOfScope = Reservation::create(['user_id' => $otherCustomer->id]);
    makeBooking($inScope,    $ownedType,   '2026-05-01', '2026-05-03');
    makeBooking($outOfScope, $foreignType, '2026-05-04', '2026-05-06');

    $this->actingAs($manager)
        ->getJson('/api/reservations')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $inScope->id);
});

test('superadmin sees every reservation', function () {
    [, $type] = seedHotelAndType();
    $c1 = customerActor();
    $c2 = customerActor();
    $r1 = Reservation::create(['user_id' => $c1->id]);
    $r2 = Reservation::create(['user_id' => $c2->id]);
    makeBooking($r1, $type, '2026-05-01', '2026-05-03');
    makeBooking($r2, $type, '2026-05-10', '2026-05-12');

    $admin = User::factory()->create();
    $admin->assignRole('superadmin');

    $this->actingAs($admin)
        ->getJson('/api/reservations')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('seatPoolOn aggregates confirmed rooms on a given date', function () {
    [, $typeA] = seedHotelAndType(roomCount: 2, capacity: 2, price: 100);
    [, $typeB] = seedHotelAndType(roomCount: 2, capacity: 3, price: 150);

    $customer    = customerActor();
    $reservation = Reservation::create(['user_id' => $customer->id]);

    // Room A: 2 guests, 2026-05-01 → 2026-05-03
    makeBooking($reservation, $typeA, '2026-05-01', '2026-05-03', guests: 2);
    // Room B: 3 guests, 2026-05-02 → 2026-05-05
    makeBooking($reservation, $typeB, '2026-05-02', '2026-05-05', guests: 3);

    expect($reservation->seatPoolOn('2026-04-30'))->toBe(0);
    expect($reservation->seatPoolOn('2026-05-01'))->toBe(2);
    expect($reservation->seatPoolOn('2026-05-02'))->toBe(5);
    expect($reservation->seatPoolOn('2026-05-03'))->toBe(3);
    expect($reservation->seatPoolOn('2026-05-04'))->toBe(3);
    expect($reservation->seatPoolOn('2026-05-05'))->toBe(0);
});

test('cancelled rooms drop out of the seat pool', function () {
    [, $type]    = seedHotelAndType();
    $customer    = customerActor();
    $reservation = Reservation::create(['user_id' => $customer->id]);

    $booking = makeBooking($reservation, $type, '2026-05-01', '2026-05-03', guests: 2);
    expect($reservation->seatPoolOn('2026-05-01'))->toBe(2);

    $booking->update(['status' => 'cancelled', 'cancelled_at' => now()]);
    expect($reservation->seatPoolOn('2026-05-01'))->toBe(0);
});
