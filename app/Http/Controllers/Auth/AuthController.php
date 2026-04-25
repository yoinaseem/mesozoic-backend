<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Hotel;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::create($data);
        $user->assignRole('customer');

        return response()->json([
            'token' => $user->createToken('api')->plainTextToken,
            'user'  => new UserResource($user),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (!Auth::attempt($request->only('email', 'password'))) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $user = Auth::user();

        return response()->json([
            'token' => $user->createToken('api')->plainTextToken,
            'user'  => new UserResource($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => new UserResource($request->user()),
        ]);
    }

    /**
     * Hotels the caller has admin access to — used by the frontend to populate
     * scoped dropdowns (e.g. the Hotel filter on the bookings page). Superadmin
     * sees every live hotel; hotel-manager sees their pivot assignments;
     * anyone else gets an empty list.
     */
    public function meHotels(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasRole('superadmin')) {
            $hotels = Hotel::query()->orderBy('name')->get(['id', 'name']);
        } elseif ($user->hasRole('hotel-manager')) {
            $hotels = $user->managedHotels()
                ->orderBy('name')
                ->get(['hotels.id', 'hotels.name']);
        } else {
            $hotels = collect();
        }

        return response()->json([
            'data' => $hotels->map(fn ($h) => [
                'id'   => $h->id,
                'name' => $h->name,
            ])->values(),
        ]);
    }
}
