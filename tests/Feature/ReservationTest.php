<?php

use App\Models\Hotel;
use App\Models\ParkBooking;
use App\Models\Reservation;
use App\Models\RoomBooking;
use App\Models\RoomType;
use App\Models\ThemePark;
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

/*
|--------------------------------------------------------------------------
| Derived resource fields — bookings_summary, total_amount, status
|--------------------------------------------------------------------------
*/

function makeParkBooking(
    Reservation $reservation,
    ThemePark $park,
    string $date,
    int $guests = 2,
    string $status = 'confirmed',
    float $price = 50.0,
): ParkBooking {
    return ParkBooking::create([
        'reservation_id'  => $reservation->id,
        'park_id'         => $park->id,
        'date'            => $date,
        'guests'          => $guests,
        'status'          => $status,
        'price_per_guest' => $price,
        'total_price'     => $price * $guests,
        'cancelled_at'    => $status === 'cancelled' ? now() : null,
    ]);
}

test('bookings_summary counts confirmed bookings only across all 5 modules', function () {
    [, $type] = seedHotelAndType();
    $park     = ThemePark::factory()->create();
    $me       = customerActor();
    $r        = Reservation::create(['user_id' => $me->id]);

    // 2 confirmed rooms, 1 cancelled room
    makeBooking($r, $type, '2026-05-01', '2026-05-03', guests: 2);
    makeBooking($r, $type, '2026-05-04', '2026-05-06', guests: 2);
    makeBooking($r, $type, '2026-05-07', '2026-05-09', guests: 2, status: 'cancelled');

    // 1 confirmed park booking, 1 cancelled
    makeParkBooking($r, $park, '2026-05-02');
    makeParkBooking($r, $park, '2026-05-05', status: 'cancelled');

    $this->actingAs($me)
        ->getJson("/api/reservations/{$r->id}")
        ->assertOk()
        ->assertJsonPath('data.bookings_summary.rooms', 2)
        ->assertJsonPath('data.bookings_summary.park', 1)
        ->assertJsonPath('data.bookings_summary.beach', 0)
        ->assertJsonPath('data.bookings_summary.activity', 0)
        ->assertJsonPath('data.bookings_summary.ferry', 0);
});

test('total_amount sums confirmed total_price across all 5 modules', function () {
    [, $type] = seedHotelAndType(price: 100);
    $park     = ThemePark::factory()->create();
    $me       = customerActor();
    $r        = Reservation::create(['user_id' => $me->id]);

    // 2-night confirmed room: 100 * 2 = 200
    makeBooking($r, $type, '2026-05-01', '2026-05-03', guests: 2);
    // Cancelled room — should NOT contribute
    makeBooking($r, $type, '2026-05-07', '2026-05-09', guests: 2, status: 'cancelled');
    // 2 guests * $50 confirmed park = 100
    makeParkBooking($r, $park, '2026-05-02', guests: 2, price: 50.0);
    // Cancelled park — should NOT contribute
    makeParkBooking($r, $park, '2026-05-05', guests: 2, price: 50.0, status: 'cancelled');

    $this->actingAs($me)
        ->getJson("/api/reservations/{$r->id}")
        ->assertOk()
        ->assertJsonPath('data.total_amount', '300.00');
});

test('total_amount is "0.00" for fully-cancelled reservations', function () {
    [, $type] = seedHotelAndType();
    $me       = customerActor();
    $r        = Reservation::create(['user_id' => $me->id]);
    makeBooking($r, $type, '2026-05-01', '2026-05-03', status: 'cancelled');

    $this->actingAs($me)
        ->getJson("/api/reservations/{$r->id}")
        ->assertOk()
        ->assertJsonPath('data.total_amount', '0.00');
});

test('status is "active" when every booking is confirmed', function () {
    [, $type] = seedHotelAndType();
    $me       = customerActor();
    $r        = Reservation::create(['user_id' => $me->id]);
    makeBooking($r, $type, '2026-05-01', '2026-05-03');

    $this->actingAs($me)
        ->getJson("/api/reservations/{$r->id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'active');
});

test('status is "partial" with mixed confirmed and cancelled bookings', function () {
    [, $type] = seedHotelAndType();
    $me       = customerActor();
    $r        = Reservation::create(['user_id' => $me->id]);
    makeBooking($r, $type, '2026-05-01', '2026-05-03');
    makeBooking($r, $type, '2026-05-04', '2026-05-06', status: 'cancelled');

    $this->actingAs($me)
        ->getJson("/api/reservations/{$r->id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'partial');
});

test('status is "cancelled" when every booking is cancelled', function () {
    [, $type] = seedHotelAndType();
    $me       = customerActor();
    $r        = Reservation::create(['user_id' => $me->id]);
    makeBooking($r, $type, '2026-05-01', '2026-05-03', status: 'cancelled');

    $this->actingAs($me)
        ->getJson("/api/reservations/{$r->id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');
});

test('status is "cancelled" for a reservation with zero bookings', function () {
    $me = customerActor();
    $r  = Reservation::create(['user_id' => $me->id]);

    $this->actingAs($me)
        ->getJson("/api/reservations/{$r->id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.total_amount', '0.00')
        ->assertJsonPath('data.bookings_summary.rooms', 0);
});

/*
|--------------------------------------------------------------------------
| Index filters — status, hotel_id, customer
|--------------------------------------------------------------------------
*/

/**
 * Returns three reservations for the given customer:
 *   [active, partial, cancelled]
 */
function seedThreeStatusReservations(User $customer, RoomType $type): array
{
    $active = Reservation::create(['user_id' => $customer->id]);
    makeBooking($active, $type, '2026-05-01', '2026-05-03');

    $partial = Reservation::create(['user_id' => $customer->id]);
    makeBooking($partial, $type, '2026-05-04', '2026-05-06');
    makeBooking($partial, $type, '2026-05-07', '2026-05-09', status: 'cancelled');

    $cancelled = Reservation::create(['user_id' => $customer->id]);
    makeBooking($cancelled, $type, '2026-05-10', '2026-05-12', status: 'cancelled');

    return [$active, $partial, $cancelled];
}

test('?status=active returns only fully-confirmed reservations', function () {
    [, $type]                          = seedHotelAndType();
    $me                                = customerActor();
    [$active, , ]                      = seedThreeStatusReservations($me, $type);

    $this->actingAs($me)
        ->getJson('/api/reservations?status=active')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $active->id);
});

test('?status=partial returns only mixed reservations', function () {
    [, $type]            = seedHotelAndType();
    $me                  = customerActor();
    [, $partial, ]       = seedThreeStatusReservations($me, $type);

    $this->actingAs($me)
        ->getJson('/api/reservations?status=partial')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $partial->id);
});

test('?status=cancelled returns fully-cancelled and zero-booking reservations', function () {
    [, $type]                = seedHotelAndType();
    $me                      = customerActor();
    [, , $cancelled]         = seedThreeStatusReservations($me, $type);
    $empty                   = Reservation::create(['user_id' => $me->id]);

    $ids = collect($this->actingAs($me)
        ->getJson('/api/reservations?status=cancelled')
        ->assertOk()
        ->json('data'))
        ->pluck('id')
        ->all();

    expect($ids)->toContain($cancelled->id, $empty->id)->toHaveCount(2);
});

test('?hotel_id filters reservations to those touching the given hotel', function () {
    [$hotelA, $typeA] = seedHotelAndType();
    [$hotelB, $typeB] = seedHotelAndType();
    $me               = customerActor();

    $atA = Reservation::create(['user_id' => $me->id]);
    makeBooking($atA, $typeA, '2026-05-01', '2026-05-03');

    $atB = Reservation::create(['user_id' => $me->id]);
    makeBooking($atB, $typeB, '2026-05-04', '2026-05-06');

    $this->actingAs($me)
        ->getJson("/api/reservations?hotel_id={$hotelA->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $atA->id);
});

test('?customer matches user.name case-insensitively', function () {
    [, $type] = seedHotelAndType();

    $alice = User::factory()->create(['name' => 'Alice Wonderland']);
    $alice->assignRole('customer');
    $bob = User::factory()->create(['name' => 'Bob Builder']);
    $bob->assignRole('customer');

    $aliceRes = Reservation::create(['user_id' => $alice->id]);
    $bobRes   = Reservation::create(['user_id' => $bob->id]);
    makeBooking($aliceRes, $type, '2026-05-01', '2026-05-03');
    makeBooking($bobRes,   $type, '2026-05-04', '2026-05-06');

    $admin = User::factory()->superadmin()->create();

    $this->actingAs($admin)
        ->getJson('/api/reservations?customer=ALICE')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $aliceRes->id);
});

test('?customer matches user.email substring', function () {
    [, $type] = seedHotelAndType();

    $alice = User::factory()->create(['email' => 'alice@example.com']);
    $alice->assignRole('customer');
    $bob = User::factory()->create(['email' => 'bob@example.org']);
    $bob->assignRole('customer');

    $aliceRes = Reservation::create(['user_id' => $alice->id]);
    $bobRes   = Reservation::create(['user_id' => $bob->id]);
    makeBooking($aliceRes, $type, '2026-05-01', '2026-05-03');
    makeBooking($bobRes,   $type, '2026-05-04', '2026-05-06');

    $admin = User::factory()->superadmin()->create();

    $this->actingAs($admin)
        ->getJson('/api/reservations?customer=alice@')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $aliceRes->id);
});

test('embedded reservation on /room-bookings does not include derived fields', function () {
    [, $type] = seedHotelAndType();
    $me       = customerActor();
    $r        = Reservation::create(['user_id' => $me->id]);
    makeBooking($r, $type, '2026-05-01', '2026-05-03');

    $payload = $this->actingAs($me)
        ->getJson('/api/room-bookings')
        ->assertOk()
        ->json('data.0.reservation');

    expect($payload)->toBeArray()
        ->not->toHaveKey('bookings_summary')
        ->not->toHaveKey('total_amount')
        ->not->toHaveKey('status');
});
