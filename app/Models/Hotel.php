<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Hotel extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'address',
        'description',
        'amenities',
        'image',
    ];

    protected $casts = [
        'amenities' => 'array',
    ];

    public function rooms()
    {
        return $this->hasMany(Room::class);
    }

    public function roomTypes()
    {
        return $this->hasMany(RoomType::class);
    }

    /**
     * Users assigned to manage this hotel (scoped RBAC).
     */
    public function managers(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /**
     * Cascade archive/restore to room types + rooms. Bulk updates so every child
     * picks up the same cascade timestamp (any drift would still restore via the
     * currently-trashed check, but a consistent timestamp makes audit queries
     * sane). Force-delete is untouched — the FK restricts guard the ledger.
     */
    protected static function booted(): void
    {
        static::deleting(function (Hotel $hotel) {
            if ($hotel->isForceDeleting()) {
                return;
            }

            $ts = $hotel->freshTimestamp();
            $hotel->roomTypes()->whereNull('deleted_at')->update(['deleted_at' => $ts]);
            $hotel->rooms()->whereNull('deleted_at')->update(['deleted_at' => $ts]);
        });

        static::restored(function (Hotel $hotel) {
            RoomType::onlyTrashed()->where('hotel_id', $hotel->id)->restore();
            Room::onlyTrashed()->where('hotel_id', $hotel->id)->restore();
        });
    }
}
