<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ThemePark extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'images',
        'description',
        'capacity',
        'price',
        'contact_email',
        'contact_phone',
    ];

    protected function casts(): array
    {
        return [
            'images' => 'array',
            'capacity' => 'integer',
            'price' => 'decimal:2',
        ];
    }

    public function openingHours()
    {
        return $this->hasMany(ParkOpeningHour::class, 'park_id');
    }

    public function parkActivities()
    {
        return $this->hasMany(ParkActivity::class, 'park_id');
    }
}
