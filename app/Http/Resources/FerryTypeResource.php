<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FerryTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'image' => $this->image,
            'capacity' => $this->capacity,
            'price' => $this->price !== null ? (float) $this->price : null,
            'ferries' => FerryResource::collection($this->whenLoaded('ferries')),
            'ferries_count' => $this->whenCounted('ferries'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
