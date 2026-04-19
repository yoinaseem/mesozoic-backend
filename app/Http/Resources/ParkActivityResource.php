<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ParkActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'park_id' => $this->park_id,
            'name' => $this->name,
            'description' => $this->description,
            'price' => $this->price !== null ? (float) $this->price : null,
            'image' => $this->image,
            'duration' => $this->duration,
            'max_capacity' => $this->max_capacity,
            'is_all_day' => $this->is_all_day,
            'schedules' => ParkActivityScheduleResource::collection($this->whenLoaded('schedules')),
            'schedules_count' => $this->whenCounted('schedules'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
