<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ResolvesImageUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HotelResource extends JsonResource
{
    use ResolvesImageUrl;

    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'address'     => $this->address,
            'description' => $this->description,
            'amenities'   => $this->amenities,
            'image'       => $this->image,
            'image_url'   => $this->resolveImageUrl($this->image),
            'room_types'  => RoomTypeResource::collection($this->whenLoaded('roomTypes')),
            'created_at'  => $this->created_at,
            'updated_at'  => $this->updated_at,
        ];
    }
}
