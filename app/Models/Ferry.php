<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ferry extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'ferry_type_id',
        'name',
    ];

    public function ferryType(): BelongsTo
    {
        return $this->belongsTo(FerryType::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(FerrySchedule::class);
    }
}
