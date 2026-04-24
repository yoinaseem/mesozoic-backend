<?php

namespace App\Http\Controllers;

use App\Http\Resources\FerryBookingResource;
use App\Models\FerryBooking;
use App\Models\FerrySchedule;
use App\Models\FerryType;
use App\Models\Reservation;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FerryBookingController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', FerryBooking::class);
        $user = $request->user();

        $query = FerryBooking::query()->with([
            'reservation.user' => fn ($q) => $q->withTrashed(),
            'schedule.ferry.ferryType',
        ]);

        if ($user->hasRole('superadmin') || $user->hasRole('ferry-manager')) {
            // no scope
        } else {
            $query->whereHas('reservation', fn ($q) => $q->where('user_id', $user->id));
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($scheduleId = $request->query('ferry_schedule_id')) {
            $query->where('ferry_schedule_id', $scheduleId);
        }
        if ($reservationId = $request->query('reservation_id')) {
            $query->where('reservation_id', $reservationId);
        }
        if ($ferryId = $request->query('ferry_id')) {
            $query->whereHas('schedule', fn ($q) => $q->where('ferry_id', $ferryId));
        }
        if ($date = $request->query('travel_date')) {
            $query->whereHas('schedule', fn ($q) => $q->whereDate('travel_date', $date));
        }

        return FerryBookingResource::collection(
            $query->latest('id')->paginate(10)
        );
    }

    public function show(FerryBooking $ferryBooking): FerryBookingResource
    {
        $this->authorize('view', $ferryBooking);

        $ferryBooking->load([
            'reservation.user' => fn ($q) => $q->withTrashed(),
            'schedule.ferry.ferryType',
        ]);

        return new FerryBookingResource($ferryBooking);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', FerryBooking::class);
        $user = $request->user();

        $data = $request->validate([
            'reservation_id' => ['required', Rule::exists('reservations', 'id')],
            'ferry_schedule_id' => ['required', Rule::exists('ferry_schedules', 'id')],
            'guests' => ['required', 'integer', 'min:1'],
        ]);

        $reservation = Reservation::findOrFail($data['reservation_id']);
        $schedule = FerrySchedule::with('ferry.ferryType')->findOrFail($data['ferry_schedule_id']);

        /** @var FerryType $type */
        $type = $schedule->ferry->ferryType;
        $travelDate = $schedule->travel_date->toDateString();

        $this->assertReservationOwnedByCaller($user, $reservation);
        $this->assertScheduleBookable($schedule);
        $this->assertReservationCoversTravelDate($reservation, $travelDate, $data['guests']);
        $this->assertNoDuplicatePerSchedule($reservation->id, $schedule->id);
        $this->assertFerryCapacityAvailable($type, $schedule, $data['guests']);

        $pricePerGuest = $type->price;
        $totalPrice = bcmul((string) $pricePerGuest, (string) $data['guests'], 2);

        $booking = FerryBooking::create([
            'reservation_id' => $reservation->id,
            'ferry_schedule_id' => $schedule->id,
            'guests' => $data['guests'],
            'status' => 'confirmed',
            'price_per_guest' => $pricePerGuest,
            'total_price' => $totalPrice,
        ]);

        return (new FerryBookingResource(
            $booking->load([
            'reservation.user' => fn ($q) => $q->withTrashed(),
            'schedule.ferry.ferryType',
        ])
        ))->response()->setStatusCode(201);
    }

    public function update(Request $request, FerryBooking $ferryBooking): FerryBookingResource
    {
        $this->authorize('update', $ferryBooking);

        $data = $request->validate([
            'status' => [
                'sometimes',
                Rule::in(['confirmed', 'cancelled']),
                function ($attribute, $value, $fail) use ($ferryBooking) {
                    if ($value === 'confirmed' && $ferryBooking->status === 'cancelled') {
                        $fail('A cancelled ferry booking cannot be re-confirmed. Create a new booking instead.');
                    }
                },
            ],
            'guests' => ['sometimes', 'integer', 'min:1'],
        ]);

        if (array_key_exists('guests', $data)) {
            $schedule = $ferryBooking->schedule()->with('ferry.ferryType')->firstOrFail();
            $type = $schedule->ferry->ferryType;
            $travelDate = $schedule->travel_date->toDateString();

            $this->assertReservationCoversTravelDate($ferryBooking->reservation, $travelDate, $data['guests']);
            $this->assertFerryCapacityAvailable($type, $schedule, $data['guests'], $ferryBooking->id);

            $data['total_price'] = bcmul(
                (string) $ferryBooking->price_per_guest,
                (string) $data['guests'],
                2,
            );
        }

        if (($data['status'] ?? null) === 'cancelled' && $ferryBooking->status !== 'cancelled') {
            $data['cancelled_at'] = now();
        }

        $ferryBooking->update($data);

        return new FerryBookingResource(
            $ferryBooking->load([
            'reservation.user' => fn ($q) => $q->withTrashed(),
            'schedule.ferry.ferryType',
        ])
        );
    }

    public function destroy(FerryBooking $ferryBooking): JsonResponse
    {
        $this->authorize('delete', $ferryBooking);

        $ferryBooking->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        return response()->json(null, 204);
    }

    private function assertReservationOwnedByCaller(\App\Models\User $user, Reservation $reservation): void
    {
        if ($user->hasRole('superadmin') || $user->hasRole('ferry-manager')) {
            return;
        }

        if ($reservation->user_id !== $user->id) {
            throw ValidationException::withMessages([
                'reservation_id' => ['This reservation does not belong to you.'],
            ]);
        }
    }

    /**
     * Only 'scheduled' departures are bookable; 'cancelled' and 'completed'
     * rows are rejected, as are past-dated trips.
     */
    private function assertScheduleBookable(FerrySchedule $schedule): void
    {
        if ($schedule->status !== 'scheduled') {
            throw ValidationException::withMessages([
                'ferry_schedule_id' => ['This ferry schedule is not bookable.'],
            ]);
        }

        if ($schedule->travel_date->toDateString() < now()->toDateString()) {
            throw ValidationException::withMessages([
                'ferry_schedule_id' => ['This ferry schedule is in the past.'],
            ]);
        }
    }

    /**
     * Ferries use the *inclusive* seat-pool window (check_in_date <=
     * travel_date <= check_out_date) so arrival-day and departure-day
     * ferries are both bookable — see Reservation::ferrySeatPoolOn.
     */
    private function assertReservationCoversTravelDate(Reservation $reservation, string $travelDate, int $guests): void
    {
        $pool = $reservation->ferrySeatPoolOn($travelDate);

        if ($pool === 0) {
            throw ValidationException::withMessages([
                'ferry_schedule_id' => ['The reservation has no confirmed room covering this travel date.'],
            ]);
        }

        if ($guests > $pool) {
            throw ValidationException::withMessages([
                'guests' => ["Guests exceed the reservation's room-booking capacity of {$pool} on this travel date."],
            ]);
        }
    }

    /**
     * One booking per (reservation, schedule) among confirmed rows. Same
     * ferry on the same travel date but a different departure_time is a
     * different schedule row, so a round-trip same-day itinerary is allowed.
     */
    private function assertNoDuplicatePerSchedule(int $reservationId, int $scheduleId): void
    {
        $exists = FerryBooking::query()
            ->where('reservation_id', $reservationId)
            ->where('ferry_schedule_id', $scheduleId)
            ->where('status', 'confirmed')
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'ferry_schedule_id' => ['This reservation already has a booking on this ferry departure.'],
            ]);
        }
    }

    /**
     * Count-then-insert capacity check against ferryType.capacity. Capacity
     * lives on the type after the Hotel/RoomType-style restructure: a vessel
     * inherits its seat count from its type. Mirrors the pattern used across
     * the other booking modules.
     */
    private function assertFerryCapacityAvailable(
        FerryType $type,
        FerrySchedule $schedule,
        int $incomingGuests,
        ?int $ignoreBookingId = null,
    ): void {
        $confirmedGuests = (int) FerryBooking::query()
            ->where('ferry_schedule_id', $schedule->id)
            ->where('status', 'confirmed')
            ->when($ignoreBookingId, fn ($q) => $q->where('id', '!=', $ignoreBookingId))
            ->sum('guests');

        if (($confirmedGuests + $incomingGuests) > $type->capacity) {
            throw ValidationException::withMessages([
                'ferry_schedule_id' => ['There is no available space on this ferry.'],
            ]);
        }
    }
}
