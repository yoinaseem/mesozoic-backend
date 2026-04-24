<?php

use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\RoomBooking;
use App\Models\RoomType;
use App\Models\User;

use function Pest\Laravel\getJson;

function availabilityHotel(int $roomCount = 2, int $capacity = 2, int $price = 100): array
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

function seedAvailabilityBooking(
    RoomType $type,
    string $checkIn,
    string $checkOut,
    string $status = 'confirmed',
): RoomBooking {
    $u = User::factory()->create();
    $u->assignRole('customer');
    $r = Reservation::create(['user_id' => $u->id]);

    return RoomBooking::create([
        'reservation_id'  => $r->id,
        'hotel_id'        => $type->hotel_id,
        'room_type_id'    => $type->id,
        'status'          => $status,
        'check_in_date'   => $checkIn,
        'check_out_date'  => $checkOut,
        'guests'          => 1,
        'price_per_night' => $type->price,
        'nights'          => \Carbon\Carbon::parse($checkIn)->diffInDays(\Carbon\Carbon::parse($checkOut)),
        'total_price'     => $type->price,
    ]);
}

test('returns full availability when hotel has no bookings', function () {
    [$hotel, $type] = availabilityHotel(roomCount: 3);

    getJson("/api/hotels/{$hotel->id}/availability?from=2026-05-01&to=2026-05-03")
        ->assertOk()
        ->assertJsonPath('data.hotel_id', $hotel->id)
        ->assertJsonPath('data.from', '2026-05-01')
        ->assertJsonPath('data.to', '2026-05-03')
        ->assertJsonPath('data.totals.total', 3)
        ->assertJsonPath('data.totals.booked', 0)
        ->assertJsonPath('data.totals.free', 3)
        ->assertJsonPath('data.room_types.0.room_type_id', $type->id)
        ->assertJsonPath('data.room_types.0.total', 3)
        ->assertJsonPath('data.room_types.0.booked', 0)
        ->assertJsonPath('data.room_types.0.free', 3);
});

test('overlapping confirmed booking subtracts from free count', function () {
    [$hotel, $type] = availabilityHotel(roomCount: 3);
    seedAvailabilityBooking($type, '2026-05-02', '2026-05-04');

    getJson("/api/hotels/{$hotel->id}/availability?from=2026-05-01&to=2026-05-03")
        ->assertOk()
        ->assertJsonPath('data.room_types.0.booked', 1)
        ->assertJsonPath('data.room_types.0.free', 2)
        ->assertJsonPath('data.totals.booked', 1)
        ->assertJsonPath('data.totals.free', 2);
});

test('cancelled bookings do not consume capacity', function () {
    [$hotel, $type] = availabilityHotel(roomCount: 1);
    seedAvailabilityBooking($type, '2026-05-02', '2026-05-04', status: 'cancelled');

    getJson("/api/hotels/{$hotel->id}/availability?from=2026-05-01&to=2026-05-03")
        ->assertOk()
        ->assertJsonPath('data.room_types.0.booked', 0)
        ->assertJsonPath('data.room_types.0.free', 1);
});

test('abutting booking (checkout equals from) does not consume capacity', function () {
    [$hotel, $type] = availabilityHotel(roomCount: 1);
    // Existing booking ends on the query's from date — exclusive-checkout overlap rule
    // means this is not a conflict.
    seedAvailabilityBooking($type, '2026-04-29', '2026-05-01');

    getJson("/api/hotels/{$hotel->id}/availability?from=2026-05-01&to=2026-05-02")
        ->assertOk()
        ->assertJsonPath('data.room_types.0.booked', 0)
        ->assertJsonPath('data.room_types.0.free', 1);
});

test('fully booked room type reports zero free', function () {
    [$hotel, $type] = availabilityHotel(roomCount: 2);
    seedAvailabilityBooking($type, '2026-05-01', '2026-05-03');
    seedAvailabilityBooking($type, '2026-05-01', '2026-05-03');

    getJson("/api/hotels/{$hotel->id}/availability?from=2026-05-01&to=2026-05-03")
        ->assertOk()
        ->assertJsonPath('data.room_types.0.booked', 2)
        ->assertJsonPath('data.room_types.0.free', 0);
});

test('defaults to today and tomorrow when no dates are passed', function () {
    [$hotel, $type] = availabilityHotel(roomCount: 2);
    seedAvailabilityBooking($type, now()->toDateString(), now()->addDays(2)->toDateString());

    $response = getJson("/api/hotels/{$hotel->id}/availability")->assertOk();

    expect($response->json('data.from'))->toBe(now()->toDateString());
    expect($response->json('data.to'))->toBe(now()->addDay()->toDateString());
    $response
        ->assertJsonPath('data.room_types.0.booked', 1)
        ->assertJsonPath('data.room_types.0.free', 1);
});

test('room type with zero rooms reports total zero, free zero', function () {
    $hotel = Hotel::factory()->create();
    $hotel->roomTypes()->create(['name' => 'Empty', 'capacity' => 2, 'price' => 100]);

    getJson("/api/hotels/{$hotel->id}/availability?from=2026-05-01&to=2026-05-02")
        ->assertOk()
        ->assertJsonPath('data.room_types.0.total', 0)
        ->assertJsonPath('data.room_types.0.booked', 0)
        ->assertJsonPath('data.room_types.0.free', 0);
});

test('bookings on another hotel do not affect this hotel', function () {
    [$hotelA, $typeA] = availabilityHotel(roomCount: 1);
    [, $typeB]        = availabilityHotel(roomCount: 1);
    seedAvailabilityBooking($typeB, '2026-05-01', '2026-05-03');

    getJson("/api/hotels/{$hotelA->id}/availability?from=2026-05-01&to=2026-05-03")
        ->assertOk()
        ->assertJsonPath('data.room_types.0.room_type_id', $typeA->id)
        ->assertJsonPath('data.room_types.0.booked', 0)
        ->assertJsonPath('data.room_types.0.free', 1);
});

test('availability endpoint is public (no auth required)', function () {
    [$hotel] = availabilityHotel();

    getJson("/api/hotels/{$hotel->id}/availability")->assertOk();
});

test('to equal to from fails validation', function () {
    [$hotel] = availabilityHotel();

    getJson("/api/hotels/{$hotel->id}/availability?from=2026-05-01&to=2026-05-01")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('to');
});

test('to before from fails validation', function () {
    [$hotel] = availabilityHotel();

    getJson("/api/hotels/{$hotel->id}/availability?from=2026-05-05&to=2026-05-01")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('to');
});

test('range beyond 366 days fails validation', function () {
    [$hotel] = availabilityHotel();

    getJson("/api/hotels/{$hotel->id}/availability?from=2026-01-01&to=2027-06-01")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('to');
});

test('unknown hotel returns 404', function () {
    getJson('/api/hotels/99999/availability')->assertNotFound();
});

test('response includes a per-room array for each room type', function () {
    [$hotel, $type] = availabilityHotel(roomCount: 3);
    $rooms = $hotel->rooms()->where('room_type_id', $type->id)->orderBy('id')->get();

    $response = getJson("/api/hotels/{$hotel->id}/availability?from=2026-05-01&to=2026-05-02")
        ->assertOk();

    expect($response->json('data.room_types.0.rooms'))->toHaveCount(3);
    $response
        ->assertJsonPath('data.room_types.0.rooms.0.room_id', $rooms[0]->id)
        ->assertJsonPath('data.room_types.0.rooms.0.room_no', $rooms[0]->room_no)
        ->assertJsonPath('data.room_types.0.rooms.0.free', true)
        ->assertJsonPath('data.room_types.0.rooms.1.free', true)
        ->assertJsonPath('data.room_types.0.rooms.2.free', true);
});

test('a specifically-assigned booking marks that room as not free', function () {
    [$hotel, $type] = availabilityHotel(roomCount: 2);
    $taken = $hotel->rooms()->where('room_type_id', $type->id)->orderBy('id')->first();

    // Create a booking with an explicit room_id (mimicking post-auto-assign state).
    $u = User::factory()->create();
    $u->assignRole('customer');
    $r = Reservation::create(['user_id' => $u->id]);
    RoomBooking::create([
        'reservation_id'  => $r->id,
        'hotel_id'        => $type->hotel_id,
        'room_type_id'    => $type->id,
        'room_id'         => $taken->id,
        'status'          => 'confirmed',
        'check_in_date'   => '2026-05-02',
        'check_out_date'  => '2026-05-04',
        'guests'          => 1,
        'price_per_night' => $type->price,
        'nights'          => 2,
        'total_price'     => $type->price * 2,
    ]);

    $response = getJson("/api/hotels/{$hotel->id}/availability?from=2026-05-01&to=2026-05-03")
        ->assertOk();

    $rooms = collect($response->json('data.room_types.0.rooms'))->keyBy('room_id');
    expect($rooms[$taken->id]['free'])->toBeFalse();
    expect($rooms->except($taken->id)->pluck('free')->all())->each->toBeTrue();
});

test('cancelled booking does not mark its room as taken', function () {
    [$hotel, $type] = availabilityHotel(roomCount: 1);
    $room = $hotel->rooms()->first();

    $u = User::factory()->create();
    $u->assignRole('customer');
    $r = Reservation::create(['user_id' => $u->id]);
    RoomBooking::create([
        'reservation_id'  => $r->id,
        'hotel_id'        => $type->hotel_id,
        'room_type_id'    => $type->id,
        'room_id'         => $room->id,
        'status'          => 'cancelled',
        'check_in_date'   => '2026-05-02',
        'check_out_date'  => '2026-05-04',
        'guests'          => 1,
        'price_per_night' => $type->price,
        'nights'          => 2,
        'total_price'     => $type->price * 2,
    ]);

    getJson("/api/hotels/{$hotel->id}/availability?from=2026-05-01&to=2026-05-03")
        ->assertOk()
        ->assertJsonPath('data.room_types.0.rooms.0.free', true);
});

test('room type with zero rooms returns an empty rooms array', function () {
    $hotel = Hotel::factory()->create();
    $hotel->roomTypes()->create(['name' => 'Empty', 'capacity' => 2, 'price' => 100]);

    $response = getJson("/api/hotels/{$hotel->id}/availability?from=2026-05-01&to=2026-05-02")
        ->assertOk();

    expect($response->json('data.room_types.0.rooms'))->toBe([]);
});
