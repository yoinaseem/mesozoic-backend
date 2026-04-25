<?php

use App\Models\BeachActivity;
use App\Models\BeachActivitySchedule;
use App\Models\BeachBooking;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\RoomBooking;
use App\Models\RoomType;
use App\Models\User;
use Carbon\Carbon;

function beachSeedRoomType(int $capacity = 4, int $price = 100): RoomType
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

function beachCustomer(): User
{
    $u = User::factory()->create();
    $u->assignRole('customer');

    return $u;
}

function beachManagerUser(): User
{
    $u = User::factory()->create();
    $u->assignRole('beach-manager');

    return $u;
}

function beachConfirmedRoomBooking(
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

function beachScheduleOn(string $date, int $capacity = 12, float $price = 75.0, string $status = 'confirmed'): BeachActivitySchedule
{
    /** @var BeachActivity $activity */
    $activity = BeachActivity::factory()->create([
        'capacity' => $capacity,
        'price' => $price,
    ]);

    return $activity->schedules()->create([
        'activity_date' => $date,
        'start_time' => '09:00:00',
        'end_time' => '10:00:00',
        'status' => $status,
    ]);
}

/**
 * 1-room reservation, 3-night stay starting +3 days so +3, +4, +5 are bookable
 * and +6 is the excluded check-out day.
 */
function beachSingleRoomReservation(User $customer, int $guests = 2): array
{
    $type = beachSeedRoomType();
    $reservation = Reservation::create(['user_id' => $customer->id]);
    $checkIn = now()->addDays(3)->toDateString();
    $checkOut = now()->addDays(6)->toDateString();
    beachConfirmedRoomBooking($reservation, $type, $checkIn, $checkOut, $guests);

    return [$reservation, $checkIn, $checkOut];
}

test('unauthenticated request cannot list beach bookings', function () {
    $this->getJson('/api/beach-bookings')->assertUnauthorized();
});

test('customer books a beach session inside their stay window', function () {
    $customer = beachCustomer();
    [$reservation, $checkIn] = beachSingleRoomReservation($customer, guests: 2);
    $schedule = beachScheduleOn($checkIn, capacity: 12, price: 75.0);

    $this->actingAs($customer)->postJson('/api/beach-bookings', [
        'reservation_id' => $reservation->id,
        'beach_activity_schedule_id' => $schedule->id,
        'guests' => 2,
    ])
        ->assertCreated()
        ->assertJsonPath('data.reservation_id', $reservation->id)
        ->assertJsonPath('data.beach_activity_schedule_id', $schedule->id)
        ->assertJsonPath('data.guests', 2)
        ->assertJsonPath('data.status', 'confirmed')
        ->assertJsonPath('data.total_price', '150.00'); // 2 × $75
});

test('family across 3 rooms can book one beach session for all 6 guests', function () {
    $father = beachCustomer();
    $typeA = beachSeedRoomType(capacity: 2);
    $typeB = beachSeedRoomType(capacity: 2);
    $typeC = beachSeedRoomType(capacity: 2);

    $reservation = Reservation::create(['user_id' => $father->id]);
    $checkIn = now()->addDays(3)->toDateString();
    $checkOut = now()->addDays(6)->toDateString();

    beachConfirmedRoomBooking($reservation, $typeA, $checkIn, $checkOut, guests: 2);
    beachConfirmedRoomBooking($reservation, $typeB, $checkIn, $checkOut, guests: 2);
    beachConfirmedRoomBooking($reservation, $typeC, $checkIn, $checkOut, guests: 2);

    $schedule = beachScheduleOn($checkIn, capacity: 10);

    $this->actingAs($father)->postJson('/api/beach-bookings', [
        'reservation_id' => $reservation->id,
        'beach_activity_schedule_id' => $schedule->id,
        'guests' => 6,
    ])
        ->assertCreated()
        ->assertJsonPath('data.guests', 6);
});

test('guests cannot exceed the reservation seat pool on the schedule date', function () {
    $customer = beachCustomer();
    [$reservation, $checkIn] = beachSingleRoomReservation($customer, guests: 2);
    $schedule = beachScheduleOn($checkIn);

    $this->actingAs($customer)->postJson('/api/beach-bookings', [
        'reservation_id' => $reservation->id,
        'beach_activity_schedule_id' => $schedule->id,
        'guests' => 3,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['guests']);
});

test('beach booking on the check-out date is rejected (seat pool is 0)', function () {
    $customer = beachCustomer();
    [$reservation, , $checkOut] = beachSingleRoomReservation($customer);
    $schedule = beachScheduleOn($checkOut);

    $this->actingAs($customer)->postJson('/api/beach-bookings', [
        'reservation_id' => $reservation->id,
        'beach_activity_schedule_id' => $schedule->id,
        'guests' => 2,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['beach_activity_schedule_id']);
});

test('beach booking before check-in is rejected', function () {
    $customer = beachCustomer();
    [$reservation, $checkIn] = beachSingleRoomReservation($customer);
    $schedule = beachScheduleOn(Carbon::parse($checkIn)->subDay()->toDateString());

    $this->actingAs($customer)->postJson('/api/beach-bookings', [
        'reservation_id' => $reservation->id,
        'beach_activity_schedule_id' => $schedule->id,
        'guests' => 2,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['beach_activity_schedule_id']);
});

test('beach booking after checkout is rejected', function () {
    $customer = beachCustomer();
    [$reservation, , $checkOut] = beachSingleRoomReservation($customer);
    $schedule = beachScheduleOn(Carbon::parse($checkOut)->addDays(2)->toDateString());

    $this->actingAs($customer)->postJson('/api/beach-bookings', [
        'reservation_id' => $reservation->id,
        'beach_activity_schedule_id' => $schedule->id,
        'guests' => 2,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['beach_activity_schedule_id']);
});

test('booking a cancelled schedule is rejected', function () {
    $customer = beachCustomer();
    [$reservation, $checkIn] = beachSingleRoomReservation($customer);
    $schedule = beachScheduleOn($checkIn, status: 'cancelled');

    $this->actingAs($customer)->postJson('/api/beach-bookings', [
        'reservation_id' => $reservation->id,
        'beach_activity_schedule_id' => $schedule->id,
        'guests' => 2,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['beach_activity_schedule_id']);
});

test('duplicate (reservation, schedule) rejected among confirmed rows', function () {
    $customer = beachCustomer();
    [$reservation, $checkIn] = beachSingleRoomReservation($customer);
    $schedule = beachScheduleOn($checkIn);

    $this->actingAs($customer)->postJson('/api/beach-bookings', [
        'reservation_id' => $reservation->id,
        'beach_activity_schedule_id' => $schedule->id,
        'guests' => 2,
    ])->assertCreated();

    $this->actingAs($customer)->postJson('/api/beach-bookings', [
        'reservation_id' => $reservation->id,
        'beach_activity_schedule_id' => $schedule->id,
        'guests' => 2,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['beach_activity_schedule_id']);
});

test('same (reservation, date) allowed on a different schedule', function () {
    $customer = beachCustomer();
    [$reservation, $checkIn] = beachSingleRoomReservation($customer);
    $scheduleA = beachScheduleOn($checkIn);
    $scheduleB = beachScheduleOn($checkIn);

    $this->actingAs($customer)->postJson('/api/beach-bookings', [
        'reservation_id' => $reservation->id,
        'beach_activity_schedule_id' => $scheduleA->id,
        'guests' => 2,
    ])->assertCreated();

    $this->actingAs($customer)->postJson('/api/beach-bookings', [
        'reservation_id' => $reservation->id,
        'beach_activity_schedule_id' => $scheduleB->id,
        'guests' => 2,
    ])->assertCreated();
});

test('schedule capacity cap blocks new bookings when full', function () {
    $customer = beachCustomer();
    [$reservation, $checkIn] = beachSingleRoomReservation($customer, guests: 2);
    $schedule = beachScheduleOn($checkIn, capacity: 3); // only 3 seats

    // Pre-fill 2 of 3 seats via another customer's reservation.
    $other = beachCustomer();
    [$otherRes] = beachSingleRoomReservation($other, guests: 2);

    BeachBooking::create([
        'reservation_id' => $otherRes->id,
        'beach_activity_schedule_id' => $schedule->id,
        'guests' => 2,
        'status' => 'confirmed',
        'price_per_guest' => $schedule->activity->price,
        'total_price' => (float) $schedule->activity->price * 2,
    ]);

    // Customer wants 2 more → 2 + 2 = 4 > 3 cap.
    $this->actingAs($customer)->postJson('/api/beach-bookings', [
        'reservation_id' => $reservation->id,
        'beach_activity_schedule_id' => $schedule->id,
        'guests' => 2,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['beach_activity_schedule_id']);
});

test('reservation with only cancelled rooms has empty seat pool → rejected', function () {
    $customer = beachCustomer();
    $type = beachSeedRoomType();
    $reservation = Reservation::create(['user_id' => $customer->id]);
    $checkIn = now()->addDays(3)->toDateString();
    beachConfirmedRoomBooking(
        $reservation,
        $type,
        $checkIn,
        now()->addDays(6)->toDateString(),
        status: 'cancelled',
    );
    $schedule = beachScheduleOn($checkIn);

    $this->actingAs($customer)->postJson('/api/beach-bookings', [
        'reservation_id' => $reservation->id,
        'beach_activity_schedule_id' => $schedule->id,
        'guests' => 1,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['beach_activity_schedule_id']);
});

test('customer cannot attach a beach booking to another users reservation', function () {
    $owner = beachCustomer();
    $stranger = beachCustomer();
    [$reservation, $checkIn] = beachSingleRoomReservation($owner);
    $schedule = beachScheduleOn($checkIn);

    $this->actingAs($stranger)->postJson('/api/beach-bookings', [
        'reservation_id' => $reservation->id,
        'beach_activity_schedule_id' => $schedule->id,
        'guests' => 2,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reservation_id']);
});

test('customer cannot update a beach booking', function () {
    $customer = beachCustomer();
    [$reservation, $checkIn] = beachSingleRoomReservation($customer);
    $schedule = beachScheduleOn($checkIn);

    $booking = BeachBooking::create([
        'reservation_id' => $reservation->id,
        'beach_activity_schedule_id' => $schedule->id,
        'guests' => 2,
        'status' => 'confirmed',
        'price_per_guest' => $schedule->activity->price,
        'total_price' => (float) $schedule->activity->price * 2,
    ]);

    $this->actingAs($customer)
        ->patchJson("/api/beach-bookings/{$booking->id}", ['status' => 'cancelled'])
        ->assertForbidden();
});

test('customer cannot cancel their own beach booking (staff only)', function () {
    $customer = beachCustomer();
    [$reservation, $checkIn] = beachSingleRoomReservation($customer);
    $schedule = beachScheduleOn($checkIn);

    $booking = BeachBooking::create([
        'reservation_id' => $reservation->id,
        'beach_activity_schedule_id' => $schedule->id,
        'guests' => 2,
        'status' => 'confirmed',
        'price_per_guest' => $schedule->activity->price,
        'total_price' => (float) $schedule->activity->price * 2,
    ]);

    $this->actingAs($customer)
        ->deleteJson("/api/beach-bookings/{$booking->id}")
        ->assertForbidden();
});

test('customer cannot view another customers beach booking', function () {
    $owner = beachCustomer();
    $stranger = beachCustomer();
    [$reservation, $checkIn] = beachSingleRoomReservation($owner);
    $schedule = beachScheduleOn($checkIn);

    $booking = BeachBooking::create([
        'reservation_id' => $reservation->id,
        'beach_activity_schedule_id' => $schedule->id,
        'guests' => 2,
        'status' => 'confirmed',
        'price_per_guest' => $schedule->activity->price,
        'total_price' => (float) $schedule->activity->price * 2,
    ]);

    $this->actingAs($stranger)
        ->getJson("/api/beach-bookings/{$booking->id}")
        ->assertForbidden();
});

test('beach-manager can cancel any beach booking', function () {
    $customer = beachCustomer();
    [$reservation, $checkIn] = beachSingleRoomReservation($customer);
    $schedule = beachScheduleOn($checkIn);
    $manager = beachManagerUser();

    $booking = BeachBooking::create([
        'reservation_id' => $reservation->id,
        'beach_activity_schedule_id' => $schedule->id,
        'guests' => 2,
        'status' => 'confirmed',
        'price_per_guest' => $schedule->activity->price,
        'total_price' => (float) $schedule->activity->price * 2,
    ]);

    $this->actingAs($manager)
        ->deleteJson("/api/beach-bookings/{$booking->id}")
        ->assertNoContent();

    $booking->refresh();
    expect($booking->status)->toBe('cancelled');
    expect($booking->cancelled_at)->not->toBeNull();
});

test('beach-manager can update booking status', function () {
    $customer = beachCustomer();
    [$reservation, $checkIn] = beachSingleRoomReservation($customer);
    $schedule = beachScheduleOn($checkIn);
    $manager = beachManagerUser();

    $booking = BeachBooking::create([
        'reservation_id' => $reservation->id,
        'beach_activity_schedule_id' => $schedule->id,
        'guests' => 2,
        'status' => 'confirmed',
        'price_per_guest' => $schedule->activity->price,
        'total_price' => (float) $schedule->activity->price * 2,
    ]);

    $this->actingAs($manager)
        ->patchJson("/api/beach-bookings/{$booking->id}", ['status' => 'cancelled'])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');
});

test('superadmin sees every beach booking', function () {
    [$rA, $dA] = beachSingleRoomReservation(beachCustomer());
    [$rB, $dB] = beachSingleRoomReservation(beachCustomer());
    $sA = beachScheduleOn($dA);
    $sB = beachScheduleOn($dB);

    foreach ([[$rA, $sA], [$rB, $sB]] as [$r, $s]) {
        BeachBooking::create([
            'reservation_id' => $r->id,
            'beach_activity_schedule_id' => $s->id,
            'guests' => 2,
            'status' => 'confirmed',
            'price_per_guest' => $s->activity->price,
            'total_price' => (float) $s->activity->price * 2,
        ]);
    }

    $admin = User::factory()->create();
    $admin->assignRole('superadmin');

    $this->actingAs($admin)
        ->getJson('/api/beach-bookings')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('customer index only returns their own beach bookings', function () {
    $me = beachCustomer();
    $other = beachCustomer();
    [$rMine, $dMine] = beachSingleRoomReservation($me);
    [$rTheirs, $dTheirs] = beachSingleRoomReservation($other);
    $sMine = beachScheduleOn($dMine);
    $sTheirs = beachScheduleOn($dTheirs);

    BeachBooking::create([
        'reservation_id' => $rMine->id,
        'beach_activity_schedule_id' => $sMine->id,
        'guests' => 2,
        'status' => 'confirmed',
        'price_per_guest' => $sMine->activity->price,
        'total_price' => (float) $sMine->activity->price * 2,
    ]);
    BeachBooking::create([
        'reservation_id' => $rTheirs->id,
        'beach_activity_schedule_id' => $sTheirs->id,
        'guests' => 2,
        'status' => 'confirmed',
        'price_per_guest' => $sTheirs->activity->price,
        'total_price' => (float) $sTheirs->activity->price * 2,
    ]);

    $this->actingAs($me)
        ->getJson('/api/beach-bookings')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.reservation_id', $rMine->id);
});
