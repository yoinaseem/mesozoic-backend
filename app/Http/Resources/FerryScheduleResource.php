<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FerryScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ferry_id' => $this->ferry_id,
            'travel_date' => $this->travel_date?->format('Y-m-d'),
            'departure_time' => $this->departure_time,
            'arrival_date' => $this->arrival_date?->format('Y-m-d'),
            'arrival_time' => $this->arrival_time,
            'departure_port' => $this->departure_port,
            'arrival_port' => $this->arrival_port,
            'status' => $this->status,
            'ferry' => new FerryResource($this->whenLoaded('ferry')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
