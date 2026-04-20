<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoomBookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'reservation_id'  => $this->reservation_id,
            'hotel_id'        => $this->hotel_id,
            'room_type_id'    => $this->room_type_id,
            'room_id'         => $this->room_id,
            'status'          => $this->status,
            'check_in_date'   => $this->check_in_date?->format('Y-m-d'),
            'check_out_date'  => $this->check_out_date?->format('Y-m-d'),
            'guests'          => $this->guests,
            'price_per_night' => $this->price_per_night,
            'nights'          => $this->nights,
            'total_price'     => $this->total_price,
            'cancelled_at'    => $this->cancelled_at,
            'created_at'      => $this->created_at,
            'updated_at'      => $this->updated_at,
            'reservation'     => new ReservationResource($this->whenLoaded('reservation')),
            'hotel'           => new HotelResource($this->whenLoaded('hotel')),
            'room_type'       => new RoomTypeResource($this->whenLoaded('roomType')),
            'room'            => new RoomResource($this->whenLoaded('room')),
        ];
    }
}
