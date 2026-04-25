<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Beach activity catalogue entry. Each activity has a duration (in minutes)
 * and a per-schedule capacity. Sessions themselves are
 * BeachActivitySchedule rows.
 *
 * `duration` is a UI default for authoring new schedules — it is NOT
 * consumed at read time after the Model B redesign (DESD-97). Schedules
 * carry their own canonical start_time / end_time. Mutating an activity's
 * duration does NOT shift any existing schedule's window.
 */
class BeachActivity extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'price',
        'capacity',
        'duration',
        'image',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'capacity' => 'integer',
            'duration' => 'integer',
        ];
    }

    public function schedules()
    {
        return $this->hasMany(BeachActivitySchedule::class);
    }
}
