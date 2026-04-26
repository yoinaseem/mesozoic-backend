<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReservationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $loaded = $this->rooms_confirmed_count !== null;

        return [
            'id'            => $this->id,
            'user_id'       => $this->user_id,
            'created_at'    => $this->created_at,
            'updated_at'    => $this->updated_at,
            'user'          => new UserResource($this->whenLoaded('user')),
            'room_bookings' => RoomBookingResource::collection($this->whenLoaded('roomBookings')),
            ...$loaded ? [
                'bookings_summary' => [
                    'rooms'    => (int) $this->rooms_confirmed_count,
                    'park'     => (int) $this->park_confirmed_count,
                    'beach'    => (int) $this->beach_confirmed_count,
                    'activity' => (int) $this->activity_confirmed_count,
                    'ferry'    => (int) $this->ferry_confirmed_count,
                ],
                'total_amount' => $this->totalAmount(),
                'status'       => $this->derivedStatus(),
            ] : [],
        ];
    }

    private function totalAmount(): string
    {
        $total = '0.00';
        foreach (['rooms', 'park', 'beach', 'activity', 'ferry'] as $k) {
            $total = bcadd($total, (string) ($this->{$k.'_total_confirmed'} ?? '0'), 2);
        }
        return $total;
    }

    private function derivedStatus(): string
    {
        $confirmed = (int) $this->rooms_confirmed_count
            + (int) $this->park_confirmed_count
            + (int) $this->beach_confirmed_count
            + (int) $this->activity_confirmed_count
            + (int) $this->ferry_confirmed_count;

        if ($confirmed === 0) {
            return 'cancelled';
        }

        $cancelled = (int) $this->rooms_cancelled_count
            + (int) $this->park_cancelled_count
            + (int) $this->beach_cancelled_count
            + (int) $this->activity_cancelled_count
            + (int) $this->ferry_cancelled_count;

        return $cancelled > 0 ? 'partial' : 'active';
    }
}
