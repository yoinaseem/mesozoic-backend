<?php

namespace App\Http\Controllers;

use App\Http\Resources\RoomBookingResource;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomBooking;
use App\Models\RoomType;
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

        $reservation = $this->resolveReservation($user, $data['reservation_id'] ?? null);

        $nights      = (int) Carbon::parse($data['check_in_date'])
            ->diffInDays(Carbon::parse($data['check_out_date']));
        $totalPrice  = bcmul((string) $roomType->price, (string) $nights, 2);

        $booking = RoomBooking::create([
            'reservation_id'  => $reservation->id,
            'hotel_id'        => $roomType->hotel_id,
            'room_type_id'    => $roomType->id,
            'room_id'         => null,
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

        if ($datesChange) {
            $checkIn  = $data['check_in_date']  ?? $roomBooking->check_in_date->toDateString();
            $checkOut = $data['check_out_date'] ?? $roomBooking->check_out_date->toDateString();

            $this->assertAvailability(
                $roomBooking->room_type_id,
                $checkIn,
                $checkOut,
                $roomBooking->id,
            );

            $nights = (int) Carbon::parse($checkIn)->diffInDays(Carbon::parse($checkOut));
            $data['nights']      = $nights;
            $data['total_price'] = bcmul((string) $roomBooking->price_per_night, (string) $nights, 2);
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
     * Fewer confirmed overlapping bookings for this RoomType than the hotel
     * has rooms of that type? Then capacity is available.
     *
     * Overlap rule (exclusive checkout):
     *   A.check_in_date < B.check_out_date AND A.check_out_date > B.check_in_date
     *
     * Known limitation: this is count-then-insert. A concurrent request could
     * slip through the window; acceptable for current scope.
     */
    private function assertAvailability(
        int $roomTypeId,
        string $checkIn,
        string $checkOut,
        ?int $ignoreBookingId = null,
    ): void {
        $roomsOfType = Room::where('room_type_id', $roomTypeId)->count();

        $conflicts = RoomBooking::query()
            ->where('room_type_id', $roomTypeId)
            ->where('status', 'confirmed')
            ->whereDate('check_in_date', '<', $checkOut)
            ->whereDate('check_out_date', '>', $checkIn)
            ->when($ignoreBookingId, fn ($q) => $q->where('id', '!=', $ignoreBookingId))
            ->count();

        if ($conflicts >= $roomsOfType) {
            throw ValidationException::withMessages([
                'room_type_id' => ['No rooms of this type are available for the selected dates.'],
            ]);
        }
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
