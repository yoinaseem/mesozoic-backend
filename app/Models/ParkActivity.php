<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ParkActivity extends Model
{
    use HasFactory;

    protected $fillable = [
        'park_id',
        'name',
        'description',
        'price',
        'image',
        'duration',
        'max_capacity',
        'is_all_day',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'duration' => 'integer',
            'max_capacity' => 'integer',
            'is_all_day' => 'boolean',
        ];
    }

    public function themePark()
    {
        return $this->belongsTo(ThemePark::class, 'park_id');
    }

    public function schedules()
    {
        return $this->hasMany(ParkActivitySchedule::class, 'park_activity_id');
    }
}
