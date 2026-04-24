<?php

namespace App\Services;

use App\Models\Hotel;
use App\Models\Room;
use App\Models\RoomBooking;
use App\Models\RoomType;
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
     * Per-room-type breakdown for a hotel plus aggregated totals.
     */
    public function forHotel(Hotel $hotel, string $from, string $to): array
    {
        $roomTypes = $hotel->roomTypes()->orderBy('id')->get();

        $breakdown = $roomTypes->map(function (RoomType $rt) use ($from, $to) {
            $counts = $this->forRoomType($rt->id, $from, $to);

            return [
                'room_type_id' => $rt->id,
                'name'         => $rt->name,
                'capacity'     => $rt->capacity,
                'price'        => $rt->price,
                'total'        => $counts['total'],
                'booked'       => $counts['booked'],
                'free'         => $counts['free'],
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
}
