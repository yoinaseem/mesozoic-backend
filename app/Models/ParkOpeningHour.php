<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ParkOpeningHour extends Model
{
    use HasFactory;

    protected $fillable = [
        'park_id',
        'day',
        'open_time',
        'close_time',
    ];

    public function themePark()
    {
        return $this->belongsTo(ThemePark::class, 'park_id');
    }
}
