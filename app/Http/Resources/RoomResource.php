<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoomResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'hotel_id'     => $this->hotel_id,
            'room_type_id' => $this->room_type_id,
            'room_no'      => $this->room_no,
            'room_type'    => new RoomTypeResource($this->whenLoaded('roomType')),
            'created_at'   => $this->created_at,
            'updated_at'   => $this->updated_at,
        ];
    }
}
