<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ThemePark extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'images',
        'description',
        'capacity',
        'price',
        'contact_email',
        'contact_phone',
    ];

    protected function casts(): array
    {
        return [
            'images' => 'array',
            'capacity' => 'integer',
            'price' => 'decimal:2',
        ];
    }

    public function openingHours()
    {
        return $this->hasMany(ParkOpeningHour::class, 'park_id');
    }

    public function hourOverrides()
    {
        return $this->hasMany(ParkHourOverride::class, 'park_id');
    }

    public function parkActivities()
    {
        return $this->hasMany(ParkActivity::class, 'park_id');
    }

    public function parkBookings()
    {
        return $this->hasMany(ParkBooking::class, 'park_id');
    }

    /**
     * Resolve this park's operating hours for a calendar date.
     *
     * Precedence: a ParkHourOverride row for the date wins over the weekday
     * baseline from ParkOpeningHour. An override with both times null means
     * "closed that day" (e.g. sudden holiday). Missing baseline + missing
     * override → "not_configured", which callers should treat as closed.
     *
     * Shape mirrors the JSON emitted by /theme-parks/{theme_park}/effective-hours
     * so the endpoint and business-rule validators share one source of truth.
     */
    public function effectiveHoursOn(CarbonInterface|string $date): array
    {
        $day = $date instanceof CarbonInterface
            ? CarbonImmutable::parse($date->toDateString())
            : CarbonImmutable::parse($date);

        $dateKey = $day->format('Y-m-d');

        $override = $this->hourOverrides()->whereDate('date', $dateKey)->first();

        if ($override !== null) {
            $isClosed = $override->open_time === null && $override->close_time === null;

            return [
                'date'       => $dateKey,
                'status'     => $isClosed ? 'closed' : 'open',
                'source'     => 'override',
                'open_time'  => $override->open_time,
                'close_time' => $override->close_time,
                'note'       => $override->note,
            ];
        }

        $baseline = $this->openingHours()
            ->where('day', strtolower($day->format('l')))
            ->first();

        if ($baseline !== null) {
            return [
                'date'       => $dateKey,
                'status'     => 'open',
                'source'     => 'baseline',
                'open_time'  => $baseline->open_time,
                'close_time' => $baseline->close_time,
                'note'       => null,
            ];
        }

        return [
            'date'       => $dateKey,
            'status'     => 'not_configured',
            'source'     => null,
            'open_time'  => null,
            'close_time' => null,
            'note'       => null,
        ];
    }

    public function isOpenOn(CarbonInterface|string $date): bool
    {
        return $this->effectiveHoursOn($date)['status'] === 'open';
    }

    /**
     * Cascade archive/restore to activities + schedules. Bulk updates so every
     * child picks up the same cascade timestamp. Config rows (opening hours,
     * overrides) are left alone — they're not soft-deletable and only matter
     * again if the park is restored.
     */
    protected static function booted(): void
    {
        static::deleting(function (ThemePark $park) {
            if ($park->isForceDeleting()) {
                return;
            }

            $ts = $park->freshTimestamp();

            $park->parkActivities()->whereNull('deleted_at')->update(['deleted_at' => $ts]);

            ParkActivitySchedule::whereIn(
                'park_activity_id',
                $park->parkActivities()->withTrashed()->select('id')
            )->whereNull('deleted_at')->update(['deleted_at' => $ts]);
        });

        static::restored(function (ThemePark $park) {
            ParkActivity::onlyTrashed()
                ->where('park_id', $park->id)
                ->restore();

            ParkActivitySchedule::onlyTrashed()
                ->whereIn('park_activity_id', ParkActivity::where('park_id', $park->id)->select('id'))
                ->restore();
        });
    }
}
