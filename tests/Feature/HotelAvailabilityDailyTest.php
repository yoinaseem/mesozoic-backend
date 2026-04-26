<?php

use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\RoomBooking;
use App\Models\RoomType;
use App\Models\User;

use function Pest\Laravel\getJson;

function dailyHotel(int $roomCount = 2, int $capacity = 2, int $price = 100): array
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

function seedDailyBooking(
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

test('returns one entry per night with full free count when no bookings', function () {
    [$hotel, $type] = dailyHotel(roomCount: 3);

    $response = getJson("/api/hotels/{$hotel->id}/availability/daily?from=2026-05-01&to=2026-05-04")
        ->assertOk()
        ->assertJsonPath('data.hotel_id', $hotel->id)
        ->assertJsonPath('data.from', '2026-05-01')
        ->assertJsonPath('data.to', '2026-05-04')
        ->assertJsonPath('data.room_types.0.room_type_id', $type->id)
        ->assertJsonPath('data.room_types.0.name', 'Standard')
        ->assertJsonPath('data.room_types.0.total', 3);

    $days = $response->json('data.room_types.0.days');
    expect($days)->toHaveCount(3);
    expect(collect($days)->pluck('date')->all())
        ->toBe(['2026-05-01', '2026-05-02', '2026-05-03']);
    expect(collect($days)->pluck('free')->all())
        ->toBe([3, 3, 3]);
});

test('overlapping confirmed booking subtracts from affected nights only', function () {
    [$hotel, $type] = dailyHotel(roomCount: 3);
    seedDailyBooking($type, '2026-05-02', '2026-05-04');

    $days = getJson("/api/hotels/{$hotel->id}/availability/daily?from=2026-05-01&to=2026-05-05")
        ->assertOk()
        ->json('data.room_types.0.days');

    expect(collect($days)->keyBy('date')->map->free->all())->toBe([
        '2026-05-01' => 3,
        '2026-05-02' => 2,
        '2026-05-03' => 2,
        '2026-05-04' => 3,
    ]);
});

test('checkout day is free (half-open interval)', function () {
    [$hotel, $type] = dailyHotel(roomCount: 1);
    seedDailyBooking($type, '2026-05-02', '2026-05-03');

    $days = getJson("/api/hotels/{$hotel->id}/availability/daily?from=2026-05-01&to=2026-05-04")
        ->assertOk()
        ->json('data.room_types.0.days');

    expect(collect($days)->keyBy('date')->map->free->all())->toBe([
        '2026-05-01' => 1,
        '2026-05-02' => 0,
        '2026-05-03' => 1,
    ]);
});

test('cancelled booking does not consume any night', function () {
    [$hotel, $type] = dailyHotel(roomCount: 1);
    seedDailyBooking($type, '2026-05-02', '2026-05-04', status: 'cancelled');

    $days = getJson("/api/hotels/{$hotel->id}/availability/daily?from=2026-05-01&to=2026-05-05")
        ->assertOk()
        ->json('data.room_types.0.days');

    expect(collect($days)->pluck('free')->all())->toBe([1, 1, 1, 1]);
});

test('multiple bookings on the same night fully book it', function () {
    [$hotel, $type] = dailyHotel(roomCount: 2);
    seedDailyBooking($type, '2026-05-02', '2026-05-04');
    seedDailyBooking($type, '2026-05-03', '2026-05-05');

    $days = getJson("/api/hotels/{$hotel->id}/availability/daily?from=2026-05-01&to=2026-05-06")
        ->assertOk()
        ->json('data.room_types.0.days');

    expect(collect($days)->keyBy('date')->map->free->all())->toBe([
        '2026-05-01' => 2,
        '2026-05-02' => 1,
        '2026-05-03' => 0,
        '2026-05-04' => 1,
        '2026-05-05' => 2,
    ]);
});

test('booking spanning out of window only affects in-window nights', function () {
    [$hotel, $type] = dailyHotel(roomCount: 1);
    // Booking from before the window through to inside it.
    seedDailyBooking($type, '2026-04-28', '2026-05-03');

    $days = getJson("/api/hotels/{$hotel->id}/availability/daily?from=2026-05-01&to=2026-05-05")
        ->assertOk()
        ->json('data.room_types.0.days');

    expect(collect($days)->keyBy('date')->map->free->all())->toBe([
        '2026-05-01' => 0,
        '2026-05-02' => 0,
        '2026-05-03' => 1,
        '2026-05-04' => 1,
    ]);
});

test('booking entirely outside the window has no effect', function () {
    [$hotel, $type] = dailyHotel(roomCount: 1);
    seedDailyBooking($type, '2026-04-20', '2026-04-25');
    seedDailyBooking($type, '2026-06-01', '2026-06-05');

    $days = getJson("/api/hotels/{$hotel->id}/availability/daily?from=2026-05-01&to=2026-05-04")
        ->assertOk()
        ->json('data.room_types.0.days');

    expect(collect($days)->pluck('free')->all())->toBe([1, 1, 1]);
});

test('multi-room-type hotel reports each type independently', function () {
    $hotel = Hotel::factory()->create();
    $a = $hotel->roomTypes()->create(['name' => 'A', 'capacity' => 2, 'price' => 100]);
    $b = $hotel->roomTypes()->create(['name' => 'B', 'capacity' => 2, 'price' => 200]);
    $hotel->rooms()->create(['room_type_id' => $a->id, 'room_no' => '101']);
    $hotel->rooms()->create(['room_type_id' => $a->id, 'room_no' => '102']);
    $hotel->rooms()->create(['room_type_id' => $b->id, 'room_no' => '201']);

    seedDailyBooking($a, '2026-05-02', '2026-05-03');

    $response = getJson("/api/hotels/{$hotel->id}/availability/daily?from=2026-05-01&to=2026-05-04")
        ->assertOk();

    $byType = collect($response->json('data.room_types'))->keyBy('room_type_id');
    expect($byType[$a->id]['total'])->toBe(2);
    expect(collect($byType[$a->id]['days'])->keyBy('date')->map->free->all())->toBe([
        '2026-05-01' => 2,
        '2026-05-02' => 1,
        '2026-05-03' => 2,
    ]);
    expect($byType[$b->id]['total'])->toBe(1);
    expect(collect($byType[$b->id]['days'])->pluck('free')->all())->toBe([1, 1, 1]);
});

test('bookings on another hotel do not affect this hotel', function () {
    [$hotelA, $typeA] = dailyHotel(roomCount: 1);
    [, $typeB]        = dailyHotel(roomCount: 1);
    seedDailyBooking($typeB, '2026-05-01', '2026-05-04');

    $days = getJson("/api/hotels/{$hotelA->id}/availability/daily?from=2026-05-01&to=2026-05-04")
        ->assertOk()
        ->json('data.room_types.0.days');

    expect(collect($days)->pluck('free')->all())->toBe([1, 1, 1]);
});

test('defaults to today and tomorrow when no dates are passed', function () {
    [$hotel] = dailyHotel(roomCount: 1);

    $response = getJson("/api/hotels/{$hotel->id}/availability/daily")->assertOk();

    expect($response->json('data.from'))->toBe(now()->toDateString());
    expect($response->json('data.to'))->toBe(now()->addDay()->toDateString());
    expect($response->json('data.room_types.0.days'))->toHaveCount(1);
});

test('room type with zero rooms reports total zero, all nights zero free', function () {
    $hotel = Hotel::factory()->create();
    $hotel->roomTypes()->create(['name' => 'Empty', 'capacity' => 2, 'price' => 100]);

    $days = getJson("/api/hotels/{$hotel->id}/availability/daily?from=2026-05-01&to=2026-05-03")
        ->assertOk()
        ->assertJsonPath('data.room_types.0.total', 0)
        ->json('data.room_types.0.days');

    expect(collect($days)->pluck('free')->all())->toBe([0, 0]);
});

test('endpoint is public (no auth required)', function () {
    [$hotel] = dailyHotel();

    getJson("/api/hotels/{$hotel->id}/availability/daily")->assertOk();
});

test('to equal to from fails validation', function () {
    [$hotel] = dailyHotel();

    getJson("/api/hotels/{$hotel->id}/availability/daily?from=2026-05-01&to=2026-05-01")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('to');
});

test('to before from fails validation', function () {
    [$hotel] = dailyHotel();

    getJson("/api/hotels/{$hotel->id}/availability/daily?from=2026-05-05&to=2026-05-01")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('to');
});

test('range beyond 366 days fails validation', function () {
    [$hotel] = dailyHotel();

    getJson("/api/hotels/{$hotel->id}/availability/daily?from=2026-01-01&to=2027-06-01")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('to');
});

test('unknown hotel returns 404', function () {
    getJson('/api/hotels/99999/availability/daily')->assertNotFound();
});

test('response shape omits rooms[], capacity, price, and per-window totals', function () {
    [$hotel] = dailyHotel();

    $response = getJson("/api/hotels/{$hotel->id}/availability/daily?from=2026-05-01&to=2026-05-02")
        ->assertOk();

    $rt = $response->json('data.room_types.0');
    expect($rt)->toHaveKeys(['room_type_id', 'name', 'total', 'days']);
    expect($rt)->not->toHaveKey('rooms');
    expect($rt)->not->toHaveKey('capacity');
    expect($rt)->not->toHaveKey('price');
    expect($rt)->not->toHaveKey('booked');
    expect($response->json('data'))->not->toHaveKey('totals');
});
