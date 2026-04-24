<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BeachBooking extends Model
{
    use HasFactory;

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'reservation_id',
        'beach_activity_schedule_id',
        'guests',
        'status',
        'price_per_guest',
        'total_price',
        'cancelled_at',
    ];

    protected $casts = [
        'cancelled_at' => 'datetime',
        'guests' => 'integer',
        'price_per_guest' => 'decimal:2',
        'total_price' => 'decimal:2',
    ];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(BeachActivitySchedule::class, 'beach_activity_schedule_id');
    }
}
