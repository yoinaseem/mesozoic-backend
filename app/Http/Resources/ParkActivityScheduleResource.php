<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ParkActivityScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'park_activity_id' => $this->park_activity_id,
            'date' => $this->date?->format('Y-m-d'),
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'end_time_source' => 'explicit',
            'status' => $this->status,
            'notes' => $this->notes,
            'activity' => new ParkActivityResource($this->whenLoaded('parkActivity')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
