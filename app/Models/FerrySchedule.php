<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A FerrySchedule is a fixed slot for a vessel — e.g. "morning departure",
 * "afternoon departure". It carries no date; bookings supply travel_date.
 * One row per (ferry_id, departure_time) WHERE deleted_at IS NULL — soft-
 * deleted slots vacate the slot and can be recreated. Overnight crossings
 * are encoded implicitly as arrival_time < departure_time (Carbon wrap on
 * read).
 */
class FerrySchedule extends Model
{
    use HasFactory, SoftDeletes;

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
