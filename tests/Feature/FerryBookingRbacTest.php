<?php

use App\Models\Ferry;
use App\Models\FerryBooking;
use App\Models\FerrySchedule;
use App\Models\FerryType;
use App\Models\Hotel;
use App\Models\ParkHourOverride;
use App\Models\Reservation;
use App\Models\RoomBooking;
use App\Models\RoomType;
use App\Models\ThemePark;
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
    $type = FerryType::create([
        'name' => 'Type '.fake()->unique()->word(),
        'description' => 'Mainland → island shuttle',
        'image' => null,
        'capacity' => $capacity,
        'price' => $price,
    ]);

    return Ferry::create([
        'ferry_type_id' => $type->id,
        'name' => 'Isla Express '.fake()->unique()->numerify('###'),
    ]);
}

/**
 * Slot is a fixed catalogue row — no travel_date. Customers pick a
 * (slot, date) pair at booking time.
 */
function fbSlot(
    Ferry $ferry,
    string $departureTime = '09:00:00',
    string $arrivalTime = '11:00:00',
    string $departurePort = 'Mainland',
    string $arrivalPort = 'Isla Nublar',
): FerrySchedule {
    return $ferry->schedules()->firstOrCreate(
        ['departure_time' => $departureTime],
        [
            'arrival_time' => $arrivalTime,
            'departure_port' => $departurePort,
            'arrival_port' => $arrivalPort,
        ],
    );
}

/**
 * Seed an always-open park: a baseline opening hour for every weekday so
 * that on any travel_date the park returns isOpenOn=true. The ferry-open
 * guard iterates over every park, so we need this whenever we want the
 * happy-path tests to run alongside a park.
 */
function fbSeedAlwaysOpenPark(): ThemePark
{
    /** @var ThemePark $park */
    $park = ThemePark::create([
        'name' => 'Mesozoic Park '.fake()->unique()->word(),
        'description' => '...',
        'capacity' => 1000,
        'price' => 50,
        'contact_email' => 'park@mesozoic.test',
        'contact_phone' => '+960-000-0000',
    ]);

    foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
        $park->openingHours()->create([
            'day' => $day,
            'open_time' => '09:00:00',
            'close_time' => '18:00:00',
        ]);
    }

    return $park;
}

/**
 * 1-room reservation, 3-night stay starting +3 days. +3 = check-in
 * (arrival ferry), +4/+5 mid-stay, +6 = check-out (departure ferry)
 * — all bookable under the inclusive window.
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
    $slot = fbSlot(fbFerry(price: 40.0));

    $this->actingAs($customer)->postJson('/api/ferry-bookings', [
        'reservation_id' => $reservation->id,
        'ferry_schedule_id' => $slot->id,
        'travel_date' => $checkIn,
        'guests' => 2,
    ])
        ->assertCreated()
        ->assertJsonPath('data.reservation_id', $reservation->id)
        ->assertJsonPath('data.ferry_schedule_id', $slot->id)
        ->assertJsonPath('data.travel_date', $checkIn)
        ->assertJsonPath('data.guests', 2)
        ->assertJsonPath('data.status', 'confirmed')
        ->assertJsonPath('data.total_price', '80.00');
});

test('customer books the departure ferry on check-out day (inclusive window)', function () {
    $customer = fbCustomer();
    [$reservation, , $checkOut] = fbSingleRoomReservation($customer, guests: 2);
    $slot = fbSlot(fbFerry(), departureTime: '16:00:00', arrivalTime: '18:00:00', departurePort: 'Isla Nublar', arrivalPort: 'Mainland');

    $this->actingAs($customer)->postJson('/api/ferry-bookings', [
        'reservation_id' => $reservation->id,
        'ferry_schedule_id' => $slot->id,
        'travel_date' => $checkOut,
        'guests' => 2,
    ])->assertCreated();
});

test('customer books a mid-stay day-trip ferry', function () {
    $customer = fbCustomer();
    [$reservation, $checkIn] = fbSingleRoomReservation($customer, guests: 2);
    $midDay = Carbon::parse($checkIn)->addDay()->toDateString();
    $slot = fbSlot(fbFerry());

    $this->actingAs($customer)->postJson('/api/ferry-bookings', [
        'reservation_id' => $reservation->id,
        'ferry_schedule_id' => $slot->id,
        'travel_date' => $midDay,
        'guests' => 2,
    ])->assertCreated();
});

test('booking a ferry before check-in is rejected', function () {
    $customer = fbCustomer();
    [$reservation, $checkIn] = fbSingleRoomReservation($customer);
    $before = Carbon::parse($checkIn)->subDay()->toDateString();
    $slot = fbSlot(fbFerry());

    $this->actingAs($customer)->postJson('/api/ferry-bookings', [
        'reservation_id' => $reservation->id,
        'ferry_schedule_id' => $slot->id,
        'travel_date' => $before,
        'guests' => 2,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['travel_date']);
});

test('booking a ferry after check-out is rejected', function () {
    $customer = fbCustomer();
    [$reservation, , $checkOut] = fbSingleRoomReservation($customer);
    $after = Carbon::parse($checkOut)->addDay()->toDateString();
    $slot = fbSlot(fbFerry());

    $this->actingAs($customer)->postJson('/api/ferry-bookings', [
        'reservation_id' => $reservation->id,
        'ferry_schedule_id' => $slot->id,
        'travel_date' => $after,
        'guests' => 2,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['travel_date']);
});

test('past travel_date is rejected at validator', function () {
    $customer = fbCustomer();
    [$reservation] = fbSingleRoomReservation($customer);
    $past = now()->subDay()->toDateString();
    $slot = fbSlot(fbFerry());

    $this->actingAs($customer)->postJson('/api/ferry-bookings', [
        'reservation_id' => $reservation->id,
        'ferry_schedule_id' => $slot->id,
        'travel_date' => $past,
        'guests' => 2,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['travel_date']);
});

test('guests cannot exceed the reservation room-booking guests', function () {
    $customer = fbCustomer();
    [$reservation, $checkIn] = fbSingleRoomReservation($customer, guests: 2);
    $slot = fbSlot(fbFerry());

    $this->actingAs($customer)->postJson('/api/ferry-bookings', [
        'reservation_id' => $reservation->id,
        'ferry_schedule_id' => $slot->id,
        'travel_date' => $checkIn,
        'guests' => 3,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['guests']);
});

test('ferry capacity cap reports no available space', function () {
    $customer = fbCustomer();
    [$reservation, $checkIn] = fbSingleRoomReservation($customer, guests: 2);
    $ferry = fbFerry(capacity: 3);
    $slot = fbSlot($ferry);

    // Pre-fill 2 of 3 seats via another customer on the same (slot, date).
    $other = fbCustomer();
    [$otherRes] = fbSingleRoomReservation($other, guests: 2);
    FerryBooking::create([
        'reservation_id' => $otherRes->id,
        'ferry_schedule_id' => $slot->id,
        'travel_date' => $checkIn,
        'guests' => 2,
        'status' => 'confirmed',
        'price_per_guest' => $ferry->ferryType->price,
        'total_price' => (float) $ferry->ferryType->price * 2,
    ]);

    $this->actingAs($customer)->postJson('/api/ferry-bookings', [
        'reservation_id' => $reservation->id,
        'ferry_schedule_id' => $slot->id,
        'travel_date' => $checkIn,
        'guests' => 2,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['ferry_schedule_id'])
        ->assertJsonFragment(['ferry_schedule_id' => ['There is no available space on this ferry.']]);
});

test('capacity is per-date — same slot is bookable again on a different date', function () {
    $customer = fbCustomer();
    [$reservation, $checkIn] = fbSingleRoomReservation($customer, guests: 2);
    $secondDay = Carbon::parse($checkIn)->addDay()->toDateString();
    $ferry = fbFerry(capacity: 3);
    $slot = fbSlot($ferry);

    // Day 1 takes 2 of 3 seats (a different reservation).
    $other = fbCustomer();
    [$otherRes] = fbSingleRoomReservation($other, guests: 2);
    FerryBooking::create([
        'reservation_id' => $otherRes->id,
        'ferry_schedule_id' => $slot->id,
        'travel_date' => $checkIn,
        'guests' => 2,
        'status' => 'confirmed',
        'price_per_guest' => $ferry->ferryType->price,
        'total_price' => (float) $ferry->ferryType->price * 2,
    ]);

    // Day 2 should have full capacity available.
    $this->actingAs($customer)->postJson('/api/ferry-bookings', [
        'reservation_id' => $reservation->id,
        'ferry_schedule_id' => $slot->id,
        'travel_date' => $secondDay,
        'guests' => 2,
    ])->assertCreated();
});

test('duplicate (reservation, schedule, date) rejected among confirmed rows', function () {
    $customer = fbCustomer();
    [$reservation, $checkIn] = fbSingleRoomReservation($customer);
    $slot = fbSlot(fbFerry());

    $this->actingAs($customer)->postJson('/api/ferry-bookings', [
        'reservation_id' => $reservation->id,
        'ferry_schedule_id' => $slot->id,
        'travel_date' => $checkIn,
        'guests' => 2,
    ])->assertCreated();

    $this->actingAs($customer)->postJson('/api/ferry-bookings', [
        'reservation_id' => $reservation->id,
        'ferry_schedule_id' => $slot->id,
        'travel_date' => $checkIn,
        'guests' => 2,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['ferry_schedule_id']);
});

test('same-day round trip on two different slots is allowed', function () {
    $customer = fbCustomer();
    [$reservation, $checkIn] = fbSingleRoomReservation($customer);
    $ferry = fbFerry();
    $outbound = fbSlot($ferry, departureTime: '09:00:00', arrivalTime: '11:00:00');
    $inbound = fbSlot($ferry, departureTime: '17:00:00', arrivalTime: '19:00:00', departurePort: 'Isla Nublar', arrivalPort: 'Mainland');

    $this->actingAs($customer)->postJson('/api/ferry-bookings', [
        'reservation_id' => $reservation->id,
        'ferry_schedule_id' => $outbound->id,
        'travel_date' => $checkIn,
        'guests' => 2,
    ])->assertCreated();

    $this->actingAs($customer)->postJson('/api/ferry-bookings', [
        'reservation_id' => $reservation->id,
        'ferry_schedule_id' => $inbound->id,
        'travel_date' => $checkIn,
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
    $slot = fbSlot(fbFerry());

    $this->actingAs($customer)->postJson('/api/ferry-bookings', [
        'reservation_id' => $reservation->id,
        'ferry_schedule_id' => $slot->id,
        'travel_date' => $checkIn,
        'guests' => 1,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['travel_date']);
});

test('customer cannot attach a ferry booking to another users reservation', function () {
    $owner = fbCustomer();
    $stranger = fbCustomer();
    [$reservation, $checkIn] = fbSingleRoomReservation($owner);
    $slot = fbSlot(fbFerry());

    $this->actingAs($stranger)->postJson('/api/ferry-bookings', [
        'reservation_id' => $reservation->id,
        'ferry_schedule_id' => $slot->id,
        'travel_date' => $checkIn,
        'guests' => 2,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reservation_id']);
});

test('customer cannot update a ferry booking', function () {
    $customer = fbCustomer();
    [$reservation, $checkIn] = fbSingleRoomReservation($customer);
    $slot = fbSlot(fbFerry());

    $booking = FerryBooking::create([
        'reservation_id' => $reservation->id,
        'ferry_schedule_id' => $slot->id,
        'travel_date' => $checkIn,
        'guests' => 2,
        'status' => 'confirmed',
        'price_per_guest' => $slot->ferry->ferryType->price,
        'total_price' => (float) $slot->ferry->ferryType->price * 2,
    ]);

    $this->actingAs($customer)
        ->patchJson("/api/ferry-bookings/{$booking->id}", ['status' => 'cancelled'])
        ->assertForbidden();
});

test('customer cannot cancel their own ferry booking (staff only)', function () {
    $customer = fbCustomer();
    [$reservation, $checkIn] = fbSingleRoomReservation($customer);
    $slot = fbSlot(fbFerry());

    $booking = FerryBooking::create([
        'reservation_id' => $reservation->id,
        'ferry_schedule_id' => $slot->id,
        'travel_date' => $checkIn,
        'guests' => 2,
        'status' => 'confirmed',
        'price_per_guest' => $slot->ferry->ferryType->price,
        'total_price' => (float) $slot->ferry->ferryType->price * 2,
    ]);

    $this->actingAs($customer)
        ->deleteJson("/api/ferry-bookings/{$booking->id}")
        ->assertForbidden();
});

test('customer cannot view another customers ferry booking', function () {
    $owner = fbCustomer();
    $stranger = fbCustomer();
    [$reservation, $checkIn] = fbSingleRoomReservation($owner);
    $slot = fbSlot(fbFerry());

    $booking = FerryBooking::create([
        'reservation_id' => $reservation->id,
        'ferry_schedule_id' => $slot->id,
        'travel_date' => $checkIn,
        'guests' => 2,
        'status' => 'confirmed',
        'price_per_guest' => $slot->ferry->ferryType->price,
        'total_price' => (float) $slot->ferry->ferryType->price * 2,
    ]);

    $this->actingAs($stranger)
        ->getJson("/api/ferry-bookings/{$booking->id}")
        ->assertForbidden();
});

test('ferry-manager can cancel any ferry booking', function () {
    $customer = fbCustomer();
    [$reservation, $checkIn] = fbSingleRoomReservation($customer);
    $slot = fbSlot(fbFerry());
    $manager = fbFerryManager();

    $booking = FerryBooking::create([
        'reservation_id' => $reservation->id,
        'ferry_schedule_id' => $slot->id,
        'travel_date' => $checkIn,
        'guests' => 2,
        'status' => 'confirmed',
        'price_per_guest' => $slot->ferry->ferryType->price,
        'total_price' => (float) $slot->ferry->ferryType->price * 2,
    ]);

    $this->actingAs($manager)
        ->deleteJson("/api/ferry-bookings/{$booking->id}")
        ->assertNoContent();

    $booking->refresh();
    expect($booking->status)->toBe('cancelled');
    expect($booking->cancelled_at)->not->toBeNull();
});

test('re-confirming a cancelled ferry booking is rejected', function () {
    $customer = fbCustomer();
    [$reservation, $checkIn] = fbSingleRoomReservation($customer);
    $slot = fbSlot(fbFerry());
    $manager = fbFerryManager();

    $booking = FerryBooking::create([
        'reservation_id' => $reservation->id,
        'ferry_schedule_id' => $slot->id,
        'travel_date' => $checkIn,
        'guests' => 2,
        'status' => 'cancelled',
        'cancelled_at' => now(),
        'price_per_guest' => $slot->ferry->ferryType->price,
        'total_price' => (float) $slot->ferry->ferryType->price * 2,
    ]);

    $this->actingAs($manager)
        ->patchJson("/api/ferry-bookings/{$booking->id}", ['status' => 'confirmed'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);

    $booking->refresh();
    expect($booking->status)->toBe('cancelled');
});

test('ferry-manager can update booking status', function () {
    $customer = fbCustomer();
    [$reservation, $checkIn] = fbSingleRoomReservation($customer);
    $slot = fbSlot(fbFerry());
    $manager = fbFerryManager();

    $booking = FerryBooking::create([
        'reservation_id' => $reservation->id,
        'ferry_schedule_id' => $slot->id,
        'travel_date' => $checkIn,
        'guests' => 2,
        'status' => 'confirmed',
        'price_per_guest' => $slot->ferry->ferryType->price,
        'total_price' => (float) $slot->ferry->ferryType->price * 2,
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
    $slot = fbSlot($ferry);

    foreach ([[$rA, $dA], [$rB, $dB]] as [$res, $date]) {
        FerryBooking::create([
            'reservation_id' => $res->id,
            'ferry_schedule_id' => $slot->id,
            'travel_date' => $date,
            'guests' => 2,
            'status' => 'confirmed',
            'price_per_guest' => $ferry->ferryType->price,
            'total_price' => (float) $ferry->ferryType->price * 2,
        ]);
    }

    $admin = User::factory()->create();
    $admin->assignRole('superadmin');

    $this->actingAs($admin)
        ->getJson('/api/ferry-bookings')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('ferry booking rejected when park is closed via override on the travel date', function () {
    $customer = fbCustomer();
    [$reservation, $checkIn] = fbSingleRoomReservation($customer);
    $park = fbSeedAlwaysOpenPark();
    ParkHourOverride::create([
        'park_id' => $park->id,
        'date' => $checkIn,
        'open_time' => null,
        'close_time' => null,
        'note' => 'Public holiday',
    ]);
    $slot = fbSlot(fbFerry());

    $this->actingAs($customer)->postJson('/api/ferry-bookings', [
        'reservation_id' => $reservation->id,
        'ferry_schedule_id' => $slot->id,
        'travel_date' => $checkIn,
        'guests' => 2,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['travel_date'])
        ->assertJsonFragment(['travel_date' => ['Ferries are not available on this date because the park is closed.']]);
});

test('ferry booking rejected when park has no baseline for the travel date weekday (not_configured)', function () {
    $customer = fbCustomer();
    [$reservation, $checkIn] = fbSingleRoomReservation($customer);
    $park = ThemePark::create([
        'name' => 'Mesozoic Park '.fake()->unique()->word(),
        'description' => '...',
        'capacity' => 1000,
        'price' => 50,
        'contact_email' => 'park@mesozoic.test',
        'contact_phone' => '+960-000-0000',
    ]);
    // No opening hours seeded → every weekday returns not_configured/closed.
    expect($park->isOpenOn($checkIn))->toBeFalse();
    $slot = fbSlot(fbFerry());

    $this->actingAs($customer)->postJson('/api/ferry-bookings', [
        'reservation_id' => $reservation->id,
        'ferry_schedule_id' => $slot->id,
        'travel_date' => $checkIn,
        'guests' => 2,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['travel_date']);
});

test('ferry booking succeeds when park has baseline opening hours for the travel date', function () {
    $customer = fbCustomer();
    [$reservation, $checkIn] = fbSingleRoomReservation($customer);
    fbSeedAlwaysOpenPark();
    $slot = fbSlot(fbFerry());

    $this->actingAs($customer)->postJson('/api/ferry-bookings', [
        'reservation_id' => $reservation->id,
        'ferry_schedule_id' => $slot->id,
        'travel_date' => $checkIn,
        'guests' => 2,
    ])->assertCreated();
});

test('customer index only returns their own ferry bookings', function () {
    $me = fbCustomer();
    $other = fbCustomer();
    [$rMine, $dMine] = fbSingleRoomReservation($me);
    [$rTheirs, $dTheirs] = fbSingleRoomReservation($other);
    $ferry = fbFerry();
    $slot = fbSlot($ferry);

    FerryBooking::create([
        'reservation_id' => $rMine->id,
        'ferry_schedule_id' => $slot->id,
        'travel_date' => $dMine,
        'guests' => 2,
        'status' => 'confirmed',
        'price_per_guest' => $ferry->ferryType->price,
        'total_price' => (float) $ferry->ferryType->price * 2,
    ]);
    FerryBooking::create([
        'reservation_id' => $rTheirs->id,
        'ferry_schedule_id' => $slot->id,
        'travel_date' => $dTheirs,
        'guests' => 2,
        'status' => 'confirmed',
        'price_per_guest' => $ferry->ferryType->price,
        'total_price' => (float) $ferry->ferryType->price * 2,
    ]);

    $this->actingAs($me)
        ->getJson('/api/ferry-bookings')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.reservation_id', $rMine->id);
});
