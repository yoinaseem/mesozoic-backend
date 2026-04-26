<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class UserAccessController extends Controller
{
    use AuthorizesRequests;

    /**
     * Sync a user's roles and (when hotel-manager is among them) their
     * managed-hotel pivot in a single atomic transaction.
     *
     * Invariants enforced here (rather than the policy) because they need
     * row-level locks to be race-safe under concurrent requests:
     *  - You cannot remove your own superadmin role.
     *  - You cannot remove superadmin from the last user holding it.
     *  - Dropping hotel-manager auto-clears the managed-hotel pivot.
     */
    public function roles(Request $request, User $user): JsonResponse|UserResource
    {
        $this->authorize('assignRoles', $user);

        $data = $request->validate([
            'roles'            => ['required', 'array'],
            'roles.*'          => ['string', 'exists:roles,name'],
            'managed_hotels'   => ['sometimes', 'array'],
            'managed_hotels.*' => ['integer', 'exists:hotels,id'],
        ]);

        $result = DB::transaction(function () use ($data, $user, $request) {
            // Lock the user row so concurrent role mutations on the same
            // user serialize.
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);

            $currentlyHasSuperadmin = $locked->hasRole('superadmin');
            $willHaveSuperadmin     = in_array('superadmin', $data['roles'], true);

            if ($currentlyHasSuperadmin && ! $willHaveSuperadmin) {
                if ($request->user()->is($locked)) {
                    return response()->json([
                        'message' => 'You cannot remove your own superadmin role.',
                    ], 409);
                }

                // Lock the superadmin role row so two concurrent demotions
                // serialize on the same lock and the second sees the
                // post-first count.
                $superadminRole = Role::query()
                    ->where('name', 'superadmin')
                    ->lockForUpdate()
                    ->first();

                $superadminCount = DB::table('model_has_roles')
                    ->where('role_id', $superadminRole->id)
                    ->where('model_type', User::class)
                    ->count();

                if ($superadminCount <= 1) {
                    return response()->json([
                        'message' => 'Cannot remove the last superadmin.',
                    ], 409);
                }
            }

            $locked->syncRoles($data['roles']);

            $keepsHotelManager = in_array('hotel-manager', $data['roles'], true);

            if (! $keepsHotelManager) {
                // Losing the role detaches all hotels — pivot only exists for
                // hotel-managers.
                $locked->managedHotels()->sync([]);
            } elseif (array_key_exists('managed_hotels', $data)) {
                $locked->managedHotels()->sync($data['managed_hotels']);
            }
            // Else: hotel-manager kept, payload didn't supply managed_hotels —
            // leave the existing pivot untouched.

            return $locked;
        });

        if ($result instanceof JsonResponse) {
            return $result;
        }

        return new UserResource($result->fresh());
    }

    /**
     * Sync the user's *direct* permission grants (i.e. permissions held
     * outside of any role). Role-derived permissions are unaffected.
     */
    public function permissions(Request $request, User $user): UserResource
    {
        $this->authorize('assignPermissions', $user);

        $data = $request->validate([
            'permissions'   => ['present', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        $user->syncPermissions($data['permissions']);

        return new UserResource($user->fresh());
    }
}
