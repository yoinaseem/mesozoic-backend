<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ThemeParkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'images' => $this->images,
            'description' => $this->description,
            'capacity' => $this->capacity,
            'price' => $this->price !== null ? (float) $this->price : null,
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone,
            'opening_hours' => ParkOpeningHourResource::collection($this->whenLoaded('openingHours')),
            'activities' => ParkActivityResource::collection($this->whenLoaded('parkActivities')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
