<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Theme park activity. Two flavours:
 *   - timed: is_all_day=false, duration is a UI default (in minutes) for new
 *     schedules. Schedules carry their own start_time/end_time which are the
 *     canonical window. Mutating duration does NOT shift existing schedules.
 *   - all-day: is_all_day=true, duration is null. Drop-in any time during
 *     park hours. The booking flow materializes a per-date schedule whose
 *     window mirrors effectiveHoursOn(date).
 *
 * `duration` is intentionally not consumed at read time after the Model B
 * redesign (DESD-95) — schedules are the source of truth for window
 * semantics.
 */
class ParkActivity extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'park_id',
        'name',
        'description',
        'price',
        'image',
        'duration',
        'max_capacity',
        'is_all_day',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'duration' => 'integer',
            'max_capacity' => 'integer',
            'is_all_day' => 'boolean',
        ];
    }

    public function themePark()
    {
        return $this->belongsTo(ThemePark::class, 'park_id');
    }

    public function schedules()
    {
        return $this->hasMany(ParkActivitySchedule::class, 'park_activity_id');
    }

    protected static function booted(): void
    {
        static::deleting(function (ParkActivity $activity) {
            if ($activity->isForceDeleting()) {
                return;
            }

            $ts = $activity->freshTimestamp();
            $activity->schedules()->whereNull('deleted_at')->update(['deleted_at' => $ts]);
        });

        static::restored(function (ParkActivity $activity) {
            ParkActivitySchedule::onlyTrashed()
                ->where('park_activity_id', $activity->id)
                ->restore();
        });
    }
}
