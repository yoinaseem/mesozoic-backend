<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ParkHourOverride extends Model
{
    use HasFactory;

    protected $fillable = [
        'park_id',
        'date',
        'open_time',
        'close_time',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    public function themePark()
    {
        return $this->belongsTo(ThemePark::class, 'park_id');
    }

    public function isClosed(): bool
    {
        return $this->open_time === null && $this->close_time === null;
    }
}
