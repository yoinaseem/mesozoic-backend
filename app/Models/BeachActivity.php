<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BeachActivity extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'price',
        'capacity',
        'duration',
        'image',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'capacity' => 'integer',
            'duration' => 'integer',
        ];
    }

    public function schedules()
    {
        return $this->hasMany(BeachActivitySchedule::class);
    }
}
