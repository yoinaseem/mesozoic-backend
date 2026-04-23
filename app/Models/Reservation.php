<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reservation extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function roomBookings(): HasMany
    {
        return $this->hasMany(RoomBooking::class);
    }

    public function parkBookings(): HasMany
    {
        return $this->hasMany(ParkBooking::class);
    }

    public function beachBookings(): HasMany
    {
        return $this->hasMany(BeachBooking::class);
    }

    public function parkActivityBookings(): HasMany
    {
        return $this->hasMany(ParkActivityBooking::class);
    }

    public function ferryBookings(): HasMany
    {
        return $this->hasMany(FerryBooking::class);
    }

    /**
     * Seats contributed by confirmed room bookings active on $date.
     * Ticket-booking modules (park/beach) call this to cap how many
     * tickets the reservation can hold for that service date.
     *
     * Active window: check_in_date <= $date < check_out_date (exclusive
     * checkout, symmetric with the room-availability overlap rule).
     */
    public function seatPoolOn(CarbonInterface|string $date): int
    {
        $d = $date instanceof CarbonInterface ? $date->toDateString() : $date;

        return (int) $this->roomBookings()
            ->where('status', 'confirmed')
            ->whereDate('check_in_date', '<=', $d)
            ->whereDate('check_out_date', '>', $d)
            ->sum('guests');
    }

    /**
     * Inclusive variant of seatPoolOn used by ferry bookings only.
     *
     * Ferries are transport — guests need a ferry TO the resort on the
     * check-in day and FROM the resort on the check-out day. The
     * exclusive-checkout rule that governs on-island service tickets
     * (park/beach/activities) would reject exactly those trips, so ferries
     * run inclusive on both ends: check_in_date <= $date <= check_out_date.
     */
    public function ferrySeatPoolOn(CarbonInterface|string $date): int
    {
        $d = $date instanceof CarbonInterface ? $date->toDateString() : $date;

        return (int) $this->roomBookings()
            ->where('status', 'confirmed')
            ->whereDate('check_in_date', '<=', $d)
            ->whereDate('check_out_date', '>=', $d)
            ->sum('guests');
    }
}
