<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A FerrySchedule is a fixed slot for a vessel — e.g. "morning departure",
 * "afternoon departure". It carries no date; bookings supply travel_date.
 * One row per (ferry_id, departure_time). Overnight crossings are encoded
 * implicitly as arrival_time < departure_time (Carbon wrap on read).
 */
class FerrySchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'ferry_id',
        'departure_time',
        'arrival_time',
        'departure_port',
        'arrival_port',
    ];

    public function ferry(): BelongsTo
    {
        return $this->belongsTo(Ferry::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(FerryBooking::class);
    }
}
