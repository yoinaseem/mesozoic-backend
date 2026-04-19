<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ParkHourOverrideResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'park_id' => $this->park_id,
            'date' => $this->date?->format('Y-m-d'),
            'open_time' => $this->open_time,
            'close_time' => $this->close_time,
            'is_closed' => $this->open_time === null && $this->close_time === null,
            'note' => $this->note,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
