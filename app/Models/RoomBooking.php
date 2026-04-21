<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoomBooking extends Model
{
    use HasFactory;

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
