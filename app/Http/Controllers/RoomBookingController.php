<?php

namespace App\Http\Controllers;

use App\Http\Resources\RoomBookingResource;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomBooking;
use App\Models\RoomType;
use App\Services\RoomAvailability;
use Carbon\Carbon;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RoomBookingController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', RoomBooking::class);
        $user = $request->user();

        $query = RoomBooking::query()->with(['reservation.user', 'hotel', 'roomType', 'room']);

        if ($user->hasRole('superadmin')) {
            // no scope
        } elseif ($user->hasRole('hotel-manager')) {
            $managedHotelIds = $user->managedHotels()->pluck('hotels.id');
            $query->whereIn('hotel_id', $managedHotelIds);
        } else {
            $query->whereHas('reservation', fn ($q) => $q->where('user_id', $user->id));
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($hotelId = $request->query('hotel_id')) {
            $query->where('hotel_id', $hotelId);
        }
        if ($reservationId = $request->query('reservation_id')) {
            $query->where('reservation_id', $reservationId);
        }

        return RoomBookingResource::collection(
            $query->orderByDesc('check_in_date')->paginate(15)
        );
    }

    public function show(RoomBooking $roomBooking): RoomBookingResource
    {
        $this->authorize('view', $roomBooking);

        $roomBooking->load(['reservation.user', 'hotel', 'roomType', 'room']);

        return new RoomBookingResource($roomBooking);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', RoomBooking::class);
        $user = $request->user();

        $data = $request->validate([
            'reservation_id'  => ['sometimes', 'nullable', 'integer', Rule::exists('reservations', 'id')],
            'room_type_id'    => ['required', Rule::exists('room_types', 'id')],
            'check_in_date'   => ['required', 'date', 'after_or_equal:today'],
            'check_out_date'  => ['required', 'date', 'after:check_in_date'],
            'guests'          => ['required', 'integer', 'min:1'],
        ]);

        $roomType = RoomType::findOrFail($data['room_type_id']);

        if ($data['guests'] > $roomType->capacity) {
            throw ValidationException::withMessages([
                'guests' => ["Guests exceed room-type capacity of {$roomType->capacity}."],
            ]);
        }

        $this->assertAvailability(
            $roomType->id,
            $data['check_in_date'],
            $data['check_out_date'],
        );

        $roomId = $this->pickFreeRoomId(
            $roomType->id,
            $data['check_in_date'],
            $data['check_out_date'],
        );

        $reservation = $this->resolveReservation($user, $data['reservation_id'] ?? null);

        $nights      = (int) Carbon::parse($data['check_in_date'])
            ->diffInDays(Carbon::parse($data['check_out_date']));
        $totalPrice  = bcmul((string) $roomType->price, (string) $nights, 2);

        $booking = RoomBooking::create([
            'reservation_id'  => $reservation->id,
            'hotel_id'        => $roomType->hotel_id,
            'room_type_id'    => $roomType->id,
            'room_id'         => $roomId,
            'status'          => 'confirmed',
            'check_in_date'   => $data['check_in_date'],
            'check_out_date'  => $data['check_out_date'],
            'guests'          => $data['guests'],
            'price_per_night' => $roomType->price,
            'nights'          => $nights,
            'total_price'     => $totalPrice,
        ]);

        return (new RoomBookingResource(
            $booking->load(['reservation.user', 'hotel', 'roomType', 'room'])
        ))->response()->setStatusCode(201);
    }

    public function update(Request $request, RoomBooking $roomBooking): RoomBookingResource
    {
        $this->authorize('update', $roomBooking);

        $data = $request->validate([
            'status'         => ['sometimes', Rule::in(['confirmed', 'cancelled'])],
            'check_in_date'  => ['sometimes', 'date'],
            'check_out_date' => ['sometimes', 'date', 'after:check_in_date'],
            'guests'         => ['sometimes', 'integer', 'min:1'],
            'room_id'        => [
                'sometimes', 'nullable',
                Rule::exists('rooms', 'id')->where(fn ($q) => $q
                    ->where('hotel_id', $roomBooking->hotel_id)
                    ->where('room_type_id', $roomBooking->room_type_id)),
            ],
        ]);

        $datesChange = array_key_exists('check_in_date', $data)
            || array_key_exists('check_out_date', $data);

        $effectiveIn  = $data['check_in_date']  ?? $roomBooking->check_in_date->toDateString();
        $effectiveOut = $data['check_out_date'] ?? $roomBooking->check_out_date->toDateString();

        if ($datesChange) {
            $this->assertAvailability(
                $roomBooking->room_type_id,
                $effectiveIn,
                $effectiveOut,
                $roomBooking->id,
            );

            $nights = (int) Carbon::parse($effectiveIn)->diffInDays(Carbon::parse($effectiveOut));
            $data['nights']      = $nights;
            $data['total_price'] = bcmul((string) $roomBooking->price_per_night, (string) $nights, 2);

            // If the caller didn't explicitly reassign a room, keep the current one
            // when it's still free on the new dates; otherwise auto-pick a free room
            // of the same type.
            if (! array_key_exists('room_id', $data)) {
                $service = app(RoomAvailability::class);
                $current = $roomBooking->room_id;
                $currentStillFree = $current !== null
                    && $service->isRoomFree($current, $effectiveIn, $effectiveOut, $roomBooking->id);

                if (! $currentStillFree) {
                    $data['room_id'] = $this->pickFreeRoomId(
                        $roomBooking->room_type_id,
                        $effectiveIn,
                        $effectiveOut,
                        $roomBooking->id,
                    );
                }
            }
        }

        // Explicit room_id reassignment (manager override): validate the target room
        // is actually free for the booking's effective dates.
        if (array_key_exists('room_id', $data) && $data['room_id'] !== null) {
            $free = app(RoomAvailability::class)
                ->isRoomFree($data['room_id'], $effectiveIn, $effectiveOut, $roomBooking->id);

            if (! $free) {
                throw ValidationException::withMessages([
                    'room_id' => ['This room is already booked for the selected dates.'],
                ]);
            }
        }

        if (isset($data['guests']) && $data['guests'] > $roomBooking->roomType->capacity) {
            throw ValidationException::withMessages([
                'guests' => ["Guests exceed room-type capacity of {$roomBooking->roomType->capacity}."],
            ]);
        }

        if (($data['status'] ?? null) === 'cancelled' && $roomBooking->status !== 'cancelled') {
            $data['cancelled_at'] = now();
        }

        $this->enforceSeatPoolInvariantOrFail($roomBooking->reservation);

        $roomBooking->update($data);

        return new RoomBookingResource(
            $roomBooking->load(['reservation.user', 'hotel', 'roomType', 'room'])
        );
    }

    public function destroy(RoomBooking $roomBooking): JsonResponse
    {
        $this->authorize('delete', $roomBooking);

        $this->enforceSeatPoolInvariantOrFail($roomBooking->reservation);

        $roomBooking->update([
            'status'       => 'cancelled',
            'cancelled_at' => now(),
        ]);

        return response()->json(null, 204);
    }

    /**
     * Resolve the reservation this booking attaches to. If an ID was passed,
     * verify the caller owns it; otherwise auto-create a fresh reservation
     * for the caller.
     */
    private function resolveReservation(\App\Models\User $user, ?int $reservationId): Reservation
    {
        if ($reservationId === null) {
            return Reservation::create(['user_id' => $user->id]);
        }

        $reservation = Reservation::findOrFail($reservationId);

        if (! $user->hasRole('superadmin') && $reservation->user_id !== $user->id) {
            throw ValidationException::withMessages([
                'reservation_id' => ['This reservation does not belong to you.'],
            ]);
        }

        return $reservation;
    }

    /**
     * Aggregate capacity guard. Delegates to the shared availability service so
     * this check stays in lockstep with GET /hotels/{hotel}/availability.
     *
     * Known limitation: count-then-insert. A concurrent request could slip
     * through the window; acceptable for current scope.
     */
    private function assertAvailability(
        int $roomTypeId,
        string $checkIn,
        string $checkOut,
        ?int $ignoreBookingId = null,
    ): void {
        $counts = app(RoomAvailability::class)
            ->forRoomType($roomTypeId, $checkIn, $checkOut, $ignoreBookingId);

        if ($counts['free'] <= 0) {
            throw ValidationException::withMessages([
                'room_type_id' => ['No rooms of this type are available for the selected dates.'],
            ]);
        }
    }

    /**
     * Pick the lowest-numbered room of the given type not already tied to an
     * overlapping confirmed booking. Callers must run assertAvailability first
     * so aggregate capacity is guaranteed before we get here.
     *
     * Fallback: if every room of the type is free of specific-room conflicts
     * (e.g. only legacy null-room bookings are consuming the pool), just take
     * the first room of the type. assertAvailability has already ruled out the
     * "no rooms at all" case.
     */
    private function pickFreeRoomId(
        int $roomTypeId,
        string $checkIn,
        string $checkOut,
        ?int $ignoreBookingId = null,
    ): int {
        $free = app(RoomAvailability::class)
            ->freeRoomsForType($roomTypeId, $checkIn, $checkOut, $ignoreBookingId);

        if ($free->isNotEmpty()) {
            return $free->first()->id;
        }

        return Room::where('room_type_id', $roomTypeId)->orderBy('id')->firstOrFail()->id;
    }

    /**
     * Guard against a room change (cancel/shrink/date-move) that would leave
     * previously-committed activity tickets over-allocated on some service date.
     *
     * TODO: implement when ferry/park/beach booking modules land. Each ticket
     * table will be queried for its per-date tickets per reservation; if the
     * post-change seatPoolOn(date) drops below committed tickets, throw.
     * Until then, no tickets exist and this is a no-op.
     */
    private function enforceSeatPoolInvariantOrFail(Reservation $reservation): void
    {
        // no-op until ticket-booking modules exist
    }
}
