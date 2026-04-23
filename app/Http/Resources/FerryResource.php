<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FerryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ferry_type_id' => $this->ferry_type_id,
            'name' => $this->name,
            'ferry_type' => new FerryTypeResource($this->whenLoaded('ferryType')),
            'schedules' => FerryScheduleResource::collection($this->whenLoaded('schedules')),
            'schedules_count' => $this->whenCounted('schedules'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
