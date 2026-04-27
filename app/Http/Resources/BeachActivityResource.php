<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ResolvesImageUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BeachActivityResource extends JsonResource
{
    use ResolvesImageUrl;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'price' => $this->price !== null ? (float) $this->price : null,
            'capacity' => $this->capacity,
            'duration' => $this->duration,
            'image' => $this->image,
            'image_url' => $this->resolveImageUrl($this->image),
            'schedules' => BeachActivityScheduleResource::collection($this->whenLoaded('schedules')),
            'schedules_count' => $this->whenCounted('schedules'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
