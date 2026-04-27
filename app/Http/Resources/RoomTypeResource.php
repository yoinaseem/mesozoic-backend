<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ResolvesImageUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoomTypeResource extends JsonResource
{
    use ResolvesImageUrl;

    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'hotel_id'    => $this->hotel_id,
            'name'        => $this->name,
            'description' => $this->description,
            'image'       => $this->image,
            'image_url'   => $this->resolveImageUrl($this->image),
            'capacity'    => $this->capacity,
            'price'       => $this->price,
            'amenities'   => $this->amenities,
            'rooms_count' => $this->whenCounted('rooms'),
            'created_at'  => $this->created_at,
            'updated_at'  => $this->updated_at,
        ];
    }
}
