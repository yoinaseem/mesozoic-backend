<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoomBooking extends Model
{
    use HasFactory;

    /**
     * Confirmed bookings whose checkout hasn't passed yet — the set that
     * blocks hotel/room-type/room archival. Inclusive of today so a guest
     * mid-stay still counts as an in-progress booking.
     */
    public function scopeUpcomingActive(Builder $query): Builder
    {
        return $query
            ->where('status', 'confirmed')
            ->whereDate('check_out_date', '>=', today());
    }

    protected $fillable = [
        'reservation_id',
        'hotel_id',
        'room_type_id',
        'room_id',
        'status',
        'check_in_date',
        'check_out_date',
        'guests',
        'price_per_night',
        'nights',
        'total_price',
        'cancelled_at',
    ];

    protected $casts = [
        'check_in_date'   => 'date',
        'check_out_date'  => 'date',
        'cancelled_at'    => 'datetime',
        'price_per_night' => 'decimal:2',
        'total_price'     => 'decimal:2',
        'guests'          => 'integer',
        'nights'          => 'integer',
    ];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
