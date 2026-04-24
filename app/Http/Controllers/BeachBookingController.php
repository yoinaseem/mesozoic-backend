<?php

namespace App\Http\Controllers;

use App\Http\Resources\BeachBookingResource;
use App\Models\BeachActivitySchedule;
use App\Models\BeachBooking;
use App\Models\Reservation;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BeachBookingController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', BeachBooking::class);
        $user = $request->user();

        $query = BeachBooking::query()->with([
            'reservation.user' => fn ($q) => $q->withTrashed(),
            'schedule.activity',
        ]);

        if ($user->hasRole('superadmin') || $user->hasRole('beach-manager')) {
            // no scope — beach-manager has no per-activity pivot today
        } else {
            $query->whereHas('reservation', fn ($q) => $q->where('user_id', $user->id));
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($scheduleId = $request->query('beach_activity_schedule_id')) {
            $query->where('beach_activity_schedule_id', $scheduleId);
        }
        if ($reservationId = $request->query('reservation_id')) {
            $query->where('reservation_id', $reservationId);
        }
        if ($activityId = $request->query('beach_activity_id')) {
            $query->whereHas('schedule', fn ($q) => $q->where('beach_activity_id', $activityId));
        }
        if ($date = $request->query('date')) {
            $query->whereHas('schedule', fn ($q) => $q->whereDate('activity_date', $date));
        }

        return BeachBookingResource::collection(
            $query->latest('id')->paginate(10)
        );
    }

    public function show(BeachBooking $beachBooking): BeachBookingResource
    {
        $this->authorize('view', $beachBooking);

        $beachBooking->load([
            'reservation.user' => fn ($q) => $q->withTrashed(),
            'schedule.activity',
        ]);

        return new BeachBookingResource($beachBooking);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', BeachBooking::class);
        $user = $request->user();

        $data = $request->validate([
            'reservation_id' => ['required', Rule::exists('reservations', 'id')],
            'beach_activity_schedule_id' => ['required', Rule::exists('beach_activity_schedules', 'id')],
            'guests' => ['required', 'integer', 'min:1'],
        ]);

        $reservation = Reservation::findOrFail($data['reservation_id']);
        $schedule = BeachActivitySchedule::with('activity')->findOrFail($data['beach_activity_schedule_id']);

        $this->assertReservationOwnedByCaller($user, $reservation);
        $this->assertScheduleBookable($schedule);
        $this->assertReservationActiveOn($reservation, $schedule->activity_date->toDateString(), $data['guests']);
        $this->assertNoDuplicatePerSchedule($reservation->id, $schedule->id);
        $this->assertScheduleCapacityAvailable($schedule, $data['guests']);

        $pricePerGuest = $schedule->activity->price;
        $totalPrice = bcmul((string) $pricePerGuest, (string) $data['guests'], 2);

        $booking = BeachBooking::create([
            'reservation_id' => $reservation->id,
            'beach_activity_schedule_id' => $schedule->id,
            'guests' => $data['guests'],
            'status' => 'confirmed',
            'price_per_guest' => $pricePerGuest,
            'total_price' => $totalPrice,
        ]);

        return (new BeachBookingResource(
            $booking->load([
            'reservation.user' => fn ($q) => $q->withTrashed(),
            'schedule.activity',
        ])
        ))->response()->setStatusCode(201);
    }

    public function update(Request $request, BeachBooking $beachBooking): BeachBookingResource
    {
        $this->authorize('update', $beachBooking);

        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['confirmed', 'cancelled'])],
            'guests' => ['sometimes', 'integer', 'min:1'],
        ]);

        if (array_key_exists('guests', $data)) {
            $schedule = $beachBooking->schedule()->with('activity')->firstOrFail();
            $date = $schedule->activity_date->toDateString();

            $this->assertReservationActiveOn($beachBooking->reservation, $date, $data['guests']);
            $this->assertScheduleCapacityAvailable($schedule, $data['guests'], $beachBooking->id);

            $data['total_price'] = bcmul(
                (string) $beachBooking->price_per_guest,
                (string) $data['guests'],
                2,
            );
        }

        if (($data['status'] ?? null) === 'cancelled' && $beachBooking->status !== 'cancelled') {
            $data['cancelled_at'] = now();
        }

        $beachBooking->update($data);

        return new BeachBookingResource(
            $beachBooking->load([
            'reservation.user' => fn ($q) => $q->withTrashed(),
            'schedule.activity',
        ])
        );
    }

    public function destroy(BeachBooking $beachBooking): JsonResponse
    {
        $this->authorize('delete', $beachBooking);

        $beachBooking->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        return response()->json(null, 204);
    }

    private function assertReservationOwnedByCaller(\App\Models\User $user, Reservation $reservation): void
    {
        if ($user->hasRole('superadmin') || $user->hasRole('beach-manager')) {
            return;
        }

        if ($reservation->user_id !== $user->id) {
            throw ValidationException::withMessages([
                'reservation_id' => ['This reservation does not belong to you.'],
            ]);
        }
    }

    /**
     * Schedules marked 'cancelled' can't be booked. Past-dated schedules are
     * rejected on the date axis so the UI surfaces the right field.
     */
    private function assertScheduleBookable(BeachActivitySchedule $schedule): void
    {
        if ($schedule->status === BeachActivitySchedule::STATUS_CANCELLED) {
            throw ValidationException::withMessages([
                'beach_activity_schedule_id' => ['This schedule has been cancelled.'],
            ]);
        }

        if ($schedule->activity_date->toDateString() < now()->toDateString()) {
            throw ValidationException::withMessages([
                'beach_activity_schedule_id' => ['This schedule is in the past.'],
            ]);
        }
    }

    /**
     * Same rule as ParkBookingController: reservation must have a confirmed
     * room booking covering the schedule date (exclusive checkout), and the
     * requested guest count must fit inside that seat pool.
     */
    private function assertReservationActiveOn(Reservation $reservation, string $date, int $guests): void
    {
        $pool = $reservation->seatPoolOn($date);

        if ($pool === 0) {
            throw ValidationException::withMessages([
                'beach_activity_schedule_id' => ['The reservation has no confirmed rooms active on this date (check-out day is excluded).'],
            ]);
        }

        if ($guests > $pool) {
            throw ValidationException::withMessages([
                'guests' => ["Guests exceed the reservation's seat pool of {$pool} on this date."],
            ]);
        }
    }

    /**
     * One booking per (reservation, schedule) among confirmed rows. Cancelled
     * rows don't count so a re-book after cancel is allowed.
     */
    private function assertNoDuplicatePerSchedule(int $reservationId, int $scheduleId): void
    {
        $exists = BeachBooking::query()
            ->where('reservation_id', $reservationId)
            ->where('beach_activity_schedule_id', $scheduleId)
            ->where('status', 'confirmed')
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'beach_activity_schedule_id' => ['This reservation already has a booking for this schedule.'],
            ]);
        }
    }

    /**
     * Count-then-insert capacity check: Σ confirmed guests on the schedule
     * plus the incoming guests must fit within activity.capacity. Mirrors
     * ParkBookingController::assertParkCapacityAvailable.
     */
    private function assertScheduleCapacityAvailable(
        BeachActivitySchedule $schedule,
        int $incomingGuests,
        ?int $ignoreBookingId = null,
    ): void {
        $confirmedGuests = (int) BeachBooking::query()
            ->where('beach_activity_schedule_id', $schedule->id)
            ->where('status', 'confirmed')
            ->when($ignoreBookingId, fn ($q) => $q->where('id', '!=', $ignoreBookingId))
            ->sum('guests');

        if (($confirmedGuests + $incomingGuests) > $schedule->activity->capacity) {
            throw ValidationException::withMessages([
                'beach_activity_schedule_id' => ['This schedule is at capacity.'],
            ]);
        }
    }
}
