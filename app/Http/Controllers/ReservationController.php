<?php

namespace App\Http\Controllers;

use App\Http\Resources\ReservationResource;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ReservationController extends Controller
{
    use AuthorizesRequests;

    /**
     * The five booking relations that contribute to a reservation's derived
     * `status`, `bookings_summary`, and `total_amount`. Order matters only
     * for readability — every aggregation walks all five.
     */
    private const BOOKING_RELATIONS = [
        'roomBookings',
        'parkBookings',
        'beachBookings',
        'parkActivityBookings',
        'ferryBookings',
    ];

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Reservation::class);
        $user = $request->user();

        $request->validate([
            'status'   => ['sometimes', Rule::in(['active', 'partial', 'cancelled'])],
            'hotel_id' => ['sometimes', 'integer', Rule::exists('hotels', 'id')],
            'customer' => ['sometimes', 'string', 'max:255'],
        ]);

        $query = Reservation::query();
        $this->applyDerivedSelects($query);

        if ($user->hasRole('superadmin')) {
            // no scope
        } elseif ($user->hasRole('hotel-manager')) {
            $managedHotelIds = $user->managedHotels()->pluck('hotels.id');
            $query->whereHas('roomBookings', fn ($q) => $q->whereIn('hotel_id', $managedHotelIds));
        } else {
            $query->where('user_id', $user->id);
        }

        if ($status = $request->query('status')) {
            $this->applyStatusFilter($query, $status);
        }
        if ($hotelId = $request->query('hotel_id')) {
            $query->whereHas('roomBookings', fn ($q) => $q->where('hotel_id', $hotelId));
        }
        if ($customer = $request->query('customer')) {
            // LOWER(...) LIKE LOWER(?) for portability — Postgres prod has ILIKE,
            // SQLite tests don't. Same query plan on Postgres if a functional
            // LOWER() index exists; otherwise both sides do a seq scan equally.
            $needle = '%'.strtolower($customer).'%';
            $query->whereHas('user', fn ($q) => $q
                ->whereRaw('LOWER(name) LIKE ?',  [$needle])
                ->orWhereRaw('LOWER(email) LIKE ?', [$needle]));
        }

        return ReservationResource::collection(
            $query->orderByDesc('created_at')->paginate(10)
        );
    }

    public function show(Reservation $reservation): ReservationResource
    {
        $this->authorize('view', $reservation);

        $query = Reservation::query()->whereKey($reservation->getKey());
        $this->applyDerivedSelects($query);

        return new ReservationResource($query->firstOrFail());
    }

    /**
     * Eager-load room bookings (for the `room_bookings` payload) plus the
     * confirmed/cancelled counts and confirmed-total sums that drive the
     * resource's `bookings_summary`, `total_amount`, and derived `status`.
     */
    private function applyDerivedSelects(Builder $query): void
    {
        $query->with([
            'user',
            'roomBookings.hotel',
            'roomBookings.roomType',
            'roomBookings.room',
        ]);

        // Field-name pairs the resource reads: ['<short>', '<eloquent-relation>']
        $modules = [
            ['rooms',    'roomBookings'],
            ['park',     'parkBookings'],
            ['beach',    'beachBookings'],
            ['activity', 'parkActivityBookings'],
            ['ferry',    'ferryBookings'],
        ];

        $countAggregates = [];
        $sumAggregates   = [];

        foreach ($modules as [$short, $relation]) {
            $countAggregates["{$relation} as {$short}_confirmed_count"] =
                fn ($q) => $q->where('status', 'confirmed');
            $countAggregates["{$relation} as {$short}_cancelled_count"] =
                fn ($q) => $q->where('status', 'cancelled');
            $sumAggregates["{$relation} as {$short}_total_confirmed"] =
                fn ($q) => $q->where('status', 'confirmed');
        }

        $query->withCount($countAggregates)
              ->withSum($sumAggregates, 'total_price');
    }

    /**
     * Translate the brief's `active | partial | cancelled` status into
     * existence/non-existence checks across all five booking relations.
     *
     *  - cancelled: zero confirmed across every module (covers fully-cancelled
     *    and zero-bookings reservations).
     *  - active:    ≥1 confirmed across some module AND zero cancelled across
     *    every module.
     *  - partial:   ≥1 confirmed across some module AND ≥1 cancelled across
     *    some module.
     */
    private function applyStatusFilter(Builder $query, string $status): void
    {
        $relations = self::BOOKING_RELATIONS;

        if ($status === 'cancelled') {
            foreach ($relations as $rel) {
                $query->whereDoesntHave($rel, fn ($q) => $q->where('status', 'confirmed'));
            }
            return;
        }

        $query->where(function (Builder $q) use ($relations) {
            foreach ($relations as $rel) {
                $q->orWhereHas($rel, fn ($r) => $r->where('status', 'confirmed'));
            }
        });

        if ($status === 'active') {
            foreach ($relations as $rel) {
                $query->whereDoesntHave($rel, fn ($q) => $q->where('status', 'cancelled'));
            }
            return;
        }

        // partial
        $query->where(function (Builder $q) use ($relations) {
            foreach ($relations as $rel) {
                $q->orWhereHas($rel, fn ($r) => $r->where('status', 'cancelled'));
            }
        });
    }
}
