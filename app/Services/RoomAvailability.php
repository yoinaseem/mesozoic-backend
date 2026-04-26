<?php

namespace App\Services;

use App\Models\Hotel;
use App\Models\Room;
use App\Models\RoomBooking;
use App\Models\RoomType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

class RoomAvailability
{
    /**
     * Free-room counts for a single room type over [from, to).
     *
     * Overlap rule (exclusive checkout):
     *   existing.check_in < query.to AND existing.check_out > query.from
     *
     * $ignoreBookingId lets the booking-update flow exclude the row being edited.
     */
    public function forRoomType(
        int $roomTypeId,
        string $from,
        string $to,
        ?int $ignoreBookingId = null,
    ): array {
        $total = Room::where('room_type_id', $roomTypeId)->count();

        $booked = RoomBooking::query()
            ->where('room_type_id', $roomTypeId)
            ->where('status', 'confirmed')
            ->whereDate('check_in_date', '<', $to)
            ->whereDate('check_out_date', '>', $from)
            ->when($ignoreBookingId, fn ($q) => $q->where('id', '!=', $ignoreBookingId))
            ->count();

        return [
            'total'  => $total,
            'booked' => $booked,
            'free'   => max(0, $total - $booked),
        ];
    }

    /**
     * Specific rooms of a type that have no confirmed overlapping booking.
     * Used by the booking flow to pick a concrete room at creation time and
     * to validate explicit room reassignments.
     */
    public function freeRoomsForType(
        int $roomTypeId,
        string $from,
        string $to,
        ?int $ignoreBookingId = null,
    ): Collection {
        $takenRoomIds = RoomBooking::query()
            ->where('room_type_id', $roomTypeId)
            ->where('status', 'confirmed')
            ->whereNotNull('room_id')
            ->whereDate('check_in_date', '<', $to)
            ->whereDate('check_out_date', '>', $from)
            ->when($ignoreBookingId, fn ($q) => $q->where('id', '!=', $ignoreBookingId))
            ->pluck('room_id');

        return Room::where('room_type_id', $roomTypeId)
            ->whereNotIn('id', $takenRoomIds)
            ->orderBy('id')
            ->get();
    }

    /**
     * True if this specific room has no confirmed overlapping booking.
     */
    public function isRoomFree(
        int $roomId,
        string $from,
        string $to,
        ?int $ignoreBookingId = null,
    ): bool {
        return ! RoomBooking::query()
            ->where('room_id', $roomId)
            ->where('status', 'confirmed')
            ->whereDate('check_in_date', '<', $to)
            ->whereDate('check_out_date', '>', $from)
            ->when($ignoreBookingId, fn ($q) => $q->where('id', '!=', $ignoreBookingId))
            ->exists();
    }

    /**
     * Per-room-type breakdown for a hotel plus aggregated totals. Each
     * room-type entry carries a `rooms` array with a {room_id, room_no, free}
     * row per physical room, so UIs can paint per-room status without a
     * second round-trip.
     *
     * Note: aggregate `booked` counts all overlapping confirmed bookings
     * (including legacy rows with null room_id). Per-room `free` flags only
     * reflect specific-room conflicts. In a clean DB (all bookings created
     * post-auto-assign) the two are always consistent.
     */
    public function forHotel(Hotel $hotel, string $from, string $to): array
    {
        $roomTypes = $hotel->roomTypes()->orderBy('id')->get();

        $breakdown = $roomTypes->map(function (RoomType $rt) use ($from, $to) {
            $counts = $this->forRoomType($rt->id, $from, $to);

            $takenRoomIds = RoomBooking::query()
                ->where('room_type_id', $rt->id)
                ->where('status', 'confirmed')
                ->whereNotNull('room_id')
                ->whereDate('check_in_date', '<', $to)
                ->whereDate('check_out_date', '>', $from)
                ->pluck('room_id')
                ->all();

            $rooms = Room::where('room_type_id', $rt->id)
                ->orderBy('id')
                ->get()
                ->map(fn (Room $r) => [
                    'room_id' => $r->id,
                    'room_no' => $r->room_no,
                    'free'    => ! in_array($r->id, $takenRoomIds, true),
                ])
                ->all();

            return [
                'room_type_id' => $rt->id,
                'name'         => $rt->name,
                'capacity'     => $rt->capacity,
                'price'        => $rt->price,
                'total'        => $counts['total'],
                'booked'       => $counts['booked'],
                'free'         => $counts['free'],
                'rooms'        => $rooms,
            ];
        })->all();

        $totals = array_reduce(
            $breakdown,
            fn ($carry, $row) => [
                'total'  => $carry['total']  + $row['total'],
                'booked' => $carry['booked'] + $row['booked'],
                'free'   => $carry['free']   + $row['free'],
            ],
            ['total' => 0, 'booked' => 0, 'free' => 0],
        );

        return [
            'room_types' => $breakdown,
            'totals'     => $totals,
        ];
    }

    /**
     * Per-room-type, per-night free counts for a hotel over [from, to).
     *
     * Drives calendar UIs that need to grey out fully-booked nights without
     * a round-trip per date. Half-open semantics: a booking with
     * check_out_date = D occupies nights up to D-1; D itself is free.
     *
     * Same fragmentation gap as forHotel(): free=2 every night across a
     * range does not guarantee a single room is free for the whole range —
     * it could be a different two rooms each night. The booking POST is
     * the backstop. Acceptable for current scope.
     */
    public function forHotelDaily(Hotel $hotel, string $from, string $to): array
    {
        $fromDt = CarbonImmutable::parse($from)->startOfDay();
        $toDt   = CarbonImmutable::parse($to)->startOfDay();

        $nights = [];
        for ($d = $fromDt; $d->lessThan($toDt); $d = $d->addDay()) {
            $nights[] = $d->format('Y-m-d');
        }

        $roomTypes = $hotel->roomTypes()->orderBy('id')->get();
        $totals = [];
        foreach ($roomTypes as $rt) {
            $totals[$rt->id] = Room::where('room_type_id', $rt->id)->count();
        }

        $bookings = RoomBooking::query()
            ->where('hotel_id', $hotel->id)
            ->where('status', 'confirmed')
            ->whereDate('check_in_date', '<', $to)
            ->whereDate('check_out_date', '>', $from)
            ->get(['room_type_id', 'check_in_date', 'check_out_date']);

        $booked = [];
        foreach ($bookings as $b) {
            $bIn  = CarbonImmutable::parse($b->check_in_date)->startOfDay();
            $bOut = CarbonImmutable::parse($b->check_out_date)->startOfDay();
            $start = $bIn->greaterThan($fromDt) ? $bIn : $fromDt;
            $end   = $bOut->lessThan($toDt) ? $bOut : $toDt;
            for ($d = $start; $d->lessThan($end); $d = $d->addDay()) {
                $key = $d->format('Y-m-d');
                $booked[$b->room_type_id][$key] = ($booked[$b->room_type_id][$key] ?? 0) + 1;
            }
        }

        return $roomTypes->map(function (RoomType $rt) use ($nights, $totals, $booked) {
            $total = $totals[$rt->id];
            $days = array_map(fn (string $date) => [
                'date' => $date,
                'free' => max(0, $total - ($booked[$rt->id][$date] ?? 0)),
            ], $nights);

            return [
                'room_type_id' => $rt->id,
                'name'         => $rt->name,
                'total'        => $total,
                'days'         => $days,
            ];
        })->all();
    }
}
