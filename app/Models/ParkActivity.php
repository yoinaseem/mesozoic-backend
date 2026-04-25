<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

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
