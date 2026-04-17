<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FerrySchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'ferry_id',
        'travel_date',
        'departure_time',
        'arrival_date',
        'arrival_time',
        'departure_port',
        'arrival_port',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'travel_date' => 'date',
            'arrival_date' => 'date',
        ];
    }

    public function ferry()
    {
        return $this->belongsTo(Ferry::class);
    }
}
