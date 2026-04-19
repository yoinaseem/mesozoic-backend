<?php

namespace App\Http\Resources;

use Carbon\Carbon;
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
            'end_time' => $this->resolveEndTime(),
            'end_time_source' => $this->end_time !== null ? 'explicit' : ($this->canDeriveEndTime() ? 'derived' : null),
            'status' => $this->status,
            'notes' => $this->notes,
            'activity' => new ParkActivityResource($this->whenLoaded('parkActivity')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function resolveEndTime(): ?string
    {
        if ($this->end_time !== null) {
            return $this->end_time;
        }

        if (! $this->canDeriveEndTime()) {
            return null;
        }

        return Carbon::createFromFormat('H:i:s', $this->start_time)
            ->addMinutes((int) $this->parkActivity->duration)
            ->format('H:i:s');
    }

    private function canDeriveEndTime(): bool
    {
        return $this->relationLoaded('parkActivity')
            && $this->parkActivity !== null
            && $this->parkActivity->duration !== null
            && $this->start_time !== null;
    }
}
