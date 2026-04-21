<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ParkBookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'room_booking_id' => $this->room_booking_id,
            'park_id'         => $this->park_id,
            'date'            => $this->date?->format('Y-m-d'),
            'guests'          => $this->guests,
            'status'          => $this->status,
            'price_per_guest' => $this->price_per_guest,
            'total_price'     => $this->total_price,
            'cancelled_at'    => $this->cancelled_at,
            'created_at'      => $this->created_at,
            'updated_at'      => $this->updated_at,
            'room_booking'    => new RoomBookingResource($this->whenLoaded('roomBooking')),
            'park'            => new ThemeParkResource($this->whenLoaded('park')),
        ];
    }
}
