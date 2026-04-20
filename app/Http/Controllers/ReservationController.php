<?php

namespace App\Http\Controllers;

use App\Http\Resources\ReservationResource;
use App\Models\Reservation;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReservationController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Reservation::class);
        $user = $request->user();

        $query = Reservation::query()->with([
            'user',
            'roomBookings.hotel',
            'roomBookings.roomType',
            'roomBookings.room',
        ]);

        if ($user->hasRole('superadmin')) {
            // no scope
        } elseif ($user->hasRole('hotel-manager')) {
            $managedHotelIds = $user->managedHotels()->pluck('hotels.id');
            $query->whereHas('roomBookings', fn ($q) => $q->whereIn('hotel_id', $managedHotelIds));
        } else {
            $query->where('user_id', $user->id);
        }

        return ReservationResource::collection(
            $query->orderByDesc('created_at')->paginate(15)
        );
    }

    public function show(Reservation $reservation): ReservationResource
    {
        $this->authorize('view', $reservation);

        $reservation->load([
            'user',
            'roomBookings.hotel',
            'roomBookings.roomType',
            'roomBookings.room',
        ]);

        return new ReservationResource($reservation);
    }
}
