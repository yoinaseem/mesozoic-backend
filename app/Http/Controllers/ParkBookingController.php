<?php

namespace App\Http\Controllers;

use App\Http\Resources\ParkBookingResource;
use App\Models\ParkBooking;
use App\Models\RoomBooking;
use App\Models\ThemePark;
use Carbon\CarbonImmutable;
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

        $query = ParkBooking::query()->with(['roomBooking.reservation.user', 'park']);

        if ($user->hasRole('superadmin') || $user->hasRole('park-manager')) {
            // no scope — park-manager sees every park today (no per-park pivot)
        } else {
            $query->whereHas('roomBooking.reservation', fn ($q) => $q->where('user_id', $user->id));
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($parkId = $request->query('park_id')) {
            $query->where('park_id', $parkId);
        }
        if ($roomBookingId = $request->query('room_booking_id')) {
            $query->where('room_booking_id', $roomBookingId);
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

        $parkBooking->load(['roomBooking.reservation.user', 'park']);

        return new ParkBookingResource($parkBooking);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', ParkBooking::class);
        $user = $request->user();

        $data = $request->validate([
            'room_booking_id' => ['required', Rule::exists('room_bookings', 'id')],
            'park_id'         => ['required', Rule::exists('theme_parks', 'id')],
            'date'            => ['required', 'date', 'after_or_equal:today'],
        ]);

        $roomBooking = RoomBooking::findOrFail($data['room_booking_id']);
        $park        = ThemePark::findOrFail($data['park_id']);

        $this->assertRoomBookingOwnedByCaller($user, $roomBooking);

        if ($roomBooking->status !== 'confirmed') {
            throw ValidationException::withMessages([
                'room_booking_id' => ['The linked room booking must be confirmed.'],
            ]);
        }

        $this->assertDateInsideStayWindow($roomBooking, $data['date']);
        $this->assertParkOpenOn($park, $data['date']);
        $this->assertNoDuplicateDayPass($roomBooking->id, $data['date']);
        $this->assertParkCapacityAvailable($park, $data['date'], $roomBooking->guests);

        $pricePerGuest = $park->price;
        $totalPrice    = bcmul((string) $pricePerGuest, (string) $roomBooking->guests, 2);

        $booking = ParkBooking::create([
            'room_booking_id' => $roomBooking->id,
            'park_id'         => $park->id,
            'date'            => $data['date'],
            'guests'          => $roomBooking->guests,
            'status'          => 'confirmed',
            'price_per_guest' => $pricePerGuest,
            'total_price'     => $totalPrice,
        ]);

        return (new ParkBookingResource(
            $booking->load(['roomBooking.reservation.user', 'park'])
        ))->response()->setStatusCode(201);
    }

    public function update(Request $request, ParkBooking $parkBooking): ParkBookingResource
    {
        $this->authorize('update', $parkBooking);

        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['confirmed', 'cancelled'])],
            'date'   => ['sometimes', 'date'],
        ]);

        if (array_key_exists('date', $data)) {
            $roomBooking = $parkBooking->roomBooking;
            $this->assertDateInsideStayWindow($roomBooking, $data['date']);
            $this->assertParkOpenOn($parkBooking->park, $data['date']);
            $this->assertNoDuplicateDayPass($roomBooking->id, $data['date'], $parkBooking->id);
            $this->assertParkCapacityAvailable(
                $parkBooking->park,
                $data['date'],
                $parkBooking->guests,
                $parkBooking->id,
            );
        }

        if (($data['status'] ?? null) === 'cancelled' && $parkBooking->status !== 'cancelled') {
            $data['cancelled_at'] = now();
        }

        $parkBooking->update($data);

        return new ParkBookingResource(
            $parkBooking->load(['roomBooking.reservation.user', 'park'])
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

    private function assertRoomBookingOwnedByCaller(\App\Models\User $user, RoomBooking $roomBooking): void
    {
        if ($user->hasRole('superadmin') || $user->hasRole('park-manager')) {
            return;
        }

        if ($roomBooking->reservation->user_id !== $user->id) {
            throw ValidationException::withMessages([
                'room_booking_id' => ['This room booking does not belong to you.'],
            ]);
        }
    }

    /**
     * Date must fall inside [check_in, check_out). Last day (check-out) is
     * excluded — guests are leaving that morning.
     */
    private function assertDateInsideStayWindow(RoomBooking $roomBooking, string $date): void
    {
        $d        = CarbonImmutable::parse($date)->startOfDay();
        $checkIn  = CarbonImmutable::parse($roomBooking->check_in_date->toDateString())->startOfDay();
        $checkOut = CarbonImmutable::parse($roomBooking->check_out_date->toDateString())->startOfDay();

        if ($d->lt($checkIn) || $d->gte($checkOut)) {
            throw ValidationException::withMessages([
                'date' => ['Park date must fall within the room booking stay (check-in included, check-out excluded).'],
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
     * One day pass per (room booking, date) among confirmed rows.
     * Cancelled rows don't count — customer can re-book after cancelling.
     */
    private function assertNoDuplicateDayPass(int $roomBookingId, string $date, ?int $ignoreBookingId = null): void
    {
        $exists = ParkBooking::query()
            ->where('room_booking_id', $roomBookingId)
            ->where('status', 'confirmed')
            ->whereDate('date', $date)
            ->when($ignoreBookingId, fn ($q) => $q->where('id', '!=', $ignoreBookingId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'date' => ['This room booking already has a park day-pass for that date.'],
            ]);
        }
    }

    /**
     * Confirmed guests across all bookings for (park, date) plus the incoming
     * guest count must not exceed park.capacity. Like RoomBookingController's
     * availability check, this is count-then-insert — acceptable for scope.
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
