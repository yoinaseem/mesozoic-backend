<?php

namespace App\Http\Controllers;

use App\Http\Resources\ParkBookingResource;
use App\Models\ParkBooking;
use App\Models\Reservation;
use App\Models\ThemePark;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ParkBookingController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ParkBooking::class);
        $user = $request->user();

        $query = ParkBooking::query()->with(['reservation.user', 'park']);

        if ($user->hasRole('superadmin') || $user->hasRole('park-manager')) {
            // no scope — park-manager has no per-park pivot today
        } else {
            $query->whereHas('reservation', fn ($q) => $q->where('user_id', $user->id));
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($parkId = $request->query('park_id')) {
            $query->where('park_id', $parkId);
        }
        if ($reservationId = $request->query('reservation_id')) {
            $query->where('reservation_id', $reservationId);
        }
        if ($date = $request->query('date')) {
            $query->whereDate('date', $date);
        }

        return ParkBookingResource::collection(
            $query->orderByDesc('date')->paginate(15)
        );
    }

    public function show(ParkBooking $parkBooking): ParkBookingResource
    {
        $this->authorize('view', $parkBooking);

        $parkBooking->load(['reservation.user', 'park']);

        return new ParkBookingResource($parkBooking);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', ParkBooking::class);
        $user = $request->user();

        $data = $request->validate([
            'reservation_id' => ['required', Rule::exists('reservations', 'id')],
            'park_id'        => ['required', Rule::exists('theme_parks', 'id')],
            'date'           => ['required', 'date', 'after_or_equal:today'],
            'guests'         => ['required', 'integer', 'min:1'],
        ]);

        $reservation = Reservation::findOrFail($data['reservation_id']);
        $park        = ThemePark::findOrFail($data['park_id']);

        $this->assertReservationOwnedByCaller($user, $reservation);
        $this->assertReservationActiveOn($reservation, $data['date'], $data['guests']);
        $this->assertParkOpenOn($park, $data['date']);
        $this->assertNoDuplicatePerPark($reservation->id, $park->id, $data['date']);
        $this->assertParkCapacityAvailable($park, $data['date'], $data['guests']);

        $pricePerGuest = $park->price;
        $totalPrice    = bcmul((string) $pricePerGuest, (string) $data['guests'], 2);

        $booking = ParkBooking::create([
            'reservation_id'  => $reservation->id,
            'park_id'         => $park->id,
            'date'            => $data['date'],
            'guests'          => $data['guests'],
            'status'          => 'confirmed',
            'price_per_guest' => $pricePerGuest,
            'total_price'     => $totalPrice,
        ]);

        return (new ParkBookingResource(
            $booking->load(['reservation.user', 'park'])
        ))->response()->setStatusCode(201);
    }

    public function update(Request $request, ParkBooking $parkBooking): ParkBookingResource
    {
        $this->authorize('update', $parkBooking);

        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['confirmed', 'cancelled'])],
            'date'   => ['sometimes', 'date'],
            'guests' => ['sometimes', 'integer', 'min:1'],
        ]);

        $dateChanges   = array_key_exists('date', $data);
        $guestsChanges = array_key_exists('guests', $data);

        if ($dateChanges || $guestsChanges) {
            $date   = $data['date']   ?? $parkBooking->date->toDateString();
            $guests = $data['guests'] ?? $parkBooking->guests;

            $this->assertReservationActiveOn($parkBooking->reservation, $date, $guests);

            if ($dateChanges) {
                $this->assertParkOpenOn($parkBooking->park, $date);
                $this->assertNoDuplicatePerPark(
                    $parkBooking->reservation_id,
                    $parkBooking->park_id,
                    $date,
                    $parkBooking->id,
                );
            }

            $this->assertParkCapacityAvailable(
                $parkBooking->park,
                $date,
                $guests,
                $parkBooking->id,
            );

            if ($guestsChanges) {
                $data['total_price'] = bcmul(
                    (string) $parkBooking->price_per_guest,
                    (string) $guests,
                    2,
                );
            }
        }

        if (($data['status'] ?? null) === 'cancelled' && $parkBooking->status !== 'cancelled') {
            $data['cancelled_at'] = now();
        }

        $parkBooking->update($data);

        return new ParkBookingResource(
            $parkBooking->load(['reservation.user', 'park'])
        );
    }

    public function destroy(ParkBooking $parkBooking): JsonResponse
    {
        $this->authorize('delete', $parkBooking);

        $parkBooking->update([
            'status'       => 'cancelled',
            'cancelled_at' => now(),
        ]);

        return response()->json(null, 204);
    }

    private function assertReservationOwnedByCaller(\App\Models\User $user, Reservation $reservation): void
    {
        if ($user->hasRole('superadmin') || $user->hasRole('park-manager')) {
            return;
        }

        if ($reservation->user_id !== $user->id) {
            throw ValidationException::withMessages([
                'reservation_id' => ['This reservation does not belong to you.'],
            ]);
        }
    }

    /**
     * A reservation is "active" on $date iff seatPoolOn($date) > 0 — i.e. at
     * least one confirmed room booking covers the date (exclusive checkout).
     * The incoming ticket count must fit inside that pool.
     *
     * This single check replaces the old check_in..check_out window logic and
     * naturally handles the multi-room case (father books 3 rooms for 6 people
     * → pool is 6 on shared dates, guests ≤ 6).
     */
    private function assertReservationActiveOn(Reservation $reservation, string $date, int $guests): void
    {
        $pool = $reservation->seatPoolOn($date);

        if ($pool === 0) {
            throw ValidationException::withMessages([
                'date' => ['The reservation has no confirmed rooms active on this date (check-out day is excluded).'],
            ]);
        }

        if ($guests > $pool) {
            throw ValidationException::withMessages([
                'guests' => ["Guests exceed the reservation's seat pool of {$pool} on this date."],
            ]);
        }
    }

    private function assertParkOpenOn(ThemePark $park, string $date): void
    {
        if (! $park->isOpenOn($date)) {
            throw ValidationException::withMessages([
                'date' => ['The park is not open on this date.'],
            ]);
        }
    }

    /**
     * One day-pass per (reservation, park, date) among confirmed rows. Cancelled
     * rows don't count — customer can re-book after cancelling. Uniqueness is
     * per-park so a multi-park future can split: parents at Park A, kids at
     * Park B on the same date. The current DB has only one park, but the rule
     * scales.
     */
    private function assertNoDuplicatePerPark(
        int $reservationId,
        int $parkId,
        string $date,
        ?int $ignoreBookingId = null,
    ): void {
        $exists = ParkBooking::query()
            ->where('reservation_id', $reservationId)
            ->where('park_id', $parkId)
            ->where('status', 'confirmed')
            ->whereDate('date', $date)
            ->when($ignoreBookingId, fn ($q) => $q->where('id', '!=', $ignoreBookingId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'date' => ['This reservation already has a day-pass for this park on that date.'],
            ]);
        }
    }

    /**
     * Count-then-insert capacity check: Σ confirmed guests on (park, date) plus
     * the incoming guests must fit within park.capacity. Mirrors the pattern
     * used by RoomBookingController::assertAvailability.
     */
    private function assertParkCapacityAvailable(
        ThemePark $park,
        string $date,
        int $incomingGuests,
        ?int $ignoreBookingId = null,
    ): void {
        $confirmedGuests = (int) ParkBooking::query()
            ->where('park_id', $park->id)
            ->where('status', 'confirmed')
            ->whereDate('date', $date)
            ->when($ignoreBookingId, fn ($q) => $q->where('id', '!=', $ignoreBookingId))
            ->sum('guests');

        if (($confirmedGuests + $incomingGuests) > $park->capacity) {
            throw ValidationException::withMessages([
                'park_id' => ['The park is at capacity for this date.'],
            ]);
        }
    }
}
