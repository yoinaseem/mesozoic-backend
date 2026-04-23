<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FerryType extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'image',
        'capacity',
        'price',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'capacity' => 'integer',
        ];
    }

    public function ferries(): HasMany
    {
        return $this->hasMany(Ferry::class);
    }
}
