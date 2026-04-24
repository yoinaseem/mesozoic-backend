<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RoomType extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'image',
        'hotel_id',
        'capacity',
        'price',
        'amenities',
    ];

    protected $casts = [
        'amenities' => 'array',
    ];

    public function rooms()
    {
        return $this->hasMany(Room::class);
    }

    public function hotel()
    {
        return $this->belongsTo(Hotel::class);
    }

    protected static function booted(): void
    {
        static::deleting(function (RoomType $type) {
            if ($type->isForceDeleting()) {
                return;
            }

            $ts = $type->freshTimestamp();
            $type->rooms()->whereNull('deleted_at')->update(['deleted_at' => $ts]);
        });

        static::restored(function (RoomType $type) {
            Room::onlyTrashed()->where('room_type_id', $type->id)->restore();
        });
    }
}
