<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\RoomBooking;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
    use AuthorizesRequests;

    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', User::class);

        return UserResource::collection(User::all());
    }

    public function show(User $user): UserResource
    {
        $this->authorize('view', $user);

        return new UserResource($user);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', User::class);

        $data = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::create($data);

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    public function update(Request $request, User $user): UserResource
    {
        $this->authorize('update', $user);

        $data = $request->validate([
            'name'     => ['sometimes', 'string', 'max:255'],
            'email'    => ['sometimes', 'email', 'unique:users,email,' . $user->id],
            'password' => ['sometimes', 'string', 'min:8', 'confirmed'],
        ]);

        $user->update($data);

        return new UserResource($user);
    }

    public function destroy(User $user): JsonResponse
    {
        $this->authorize('delete', $user);

        // Upcoming room bookings = the user is actively a guest somewhere.
        // Ticket bookings (park/beach/ferry/park-activity) are time-locked to
        // a confirmed room stay, so a clean room-booking slate implies a
        // clean ticket slate too.
        $blocking = RoomBooking::query()
            ->upcomingActive()
            ->whereHas('reservation', fn ($q) => $q->where('user_id', $user->id))
            ->count();

        if ($blocking > 0) {
            return response()->json([
                'message'           => 'Cannot archive a user with upcoming or in-progress bookings.',
                'blocking_bookings' => $blocking,
            ], 409);
        }

        $user->delete();

        return response()->json(null, 204);
    }

    public function restore(User $user): UserResource
    {
        $this->authorize('restore', $user);

        if ($user->trashed()) {
            $user->restore();
        }

        return new UserResource($user);
    }
}
