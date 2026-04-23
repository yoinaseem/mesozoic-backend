<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BeachBookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reservation_id' => $this->reservation_id,
            'beach_activity_schedule_id' => $this->beach_activity_schedule_id,
            'guests' => $this->guests,
            'status' => $this->status,
            'price_per_guest' => $this->price_per_guest,
            'total_price' => $this->total_price,
            'cancelled_at' => $this->cancelled_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'reservation' => new ReservationResource($this->whenLoaded('reservation')),
            'schedule' => new BeachActivityScheduleResource($this->whenLoaded('schedule')),
        ];
    }
}
