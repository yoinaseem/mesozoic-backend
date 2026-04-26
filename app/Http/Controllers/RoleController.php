<?php

namespace App\Http\Controllers;

use App\Http\Resources\RoleResource;
use App\Models\User;
use App\Support\SystemRoles;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    use AuthorizesRequests;

    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Role::class);

        $roles = Role::query()
            ->with('permissions')
            ->orderBy('name')
            ->get();

        $this->attachUserCounts($roles->all());

        return RoleResource::collection($roles);
    }

    public function show(Role $role): RoleResource
    {
        $this->authorize('view', $role);

        $role->load('permissions');
        $this->attachUserCounts([$role]);

        return new RoleResource($role);
    }

    /**
     * Populate `users_count` on each Role without going through the Role::users()
     * morphedByMany relationship.
     *
     * Why: Spatie's Role::users() resolves its target model via getModelForGuard
     * using the role's guard_name (or, on a fresh Role instance built by
     * withCount/loadCount, the runtime config('auth.defaults.guard')). When the
     * request was authenticated through Sanctum, Laravel's Authenticate
     * middleware calls AuthManager::shouldUse('sanctum') which writes back to
     * the config — and `auth.guards.sanctum.provider` is null. The relationship
     * then explodes with "Class name must be a valid object or a string".
     *
     * Counting against the model_has_roles pivot directly avoids the entire
     * guard-resolution path and is a single grouped query.
     *
     * @param  array<int, Role>  $roles
     */
    private function attachUserCounts(array $roles): void
    {
        if ($roles === []) {
            return;
        }

        $counts = DB::table(config('permission.table_names.model_has_roles'))
            ->where('model_type', User::class)
            ->whereIn('role_id', array_map(fn (Role $r) => $r->id, $roles))
            ->select('role_id', DB::raw('COUNT(*) AS user_count'))
            ->groupBy('role_id')
            ->pluck('user_count', 'role_id');

        foreach ($roles as $role) {
            $role->users_count = (int) ($counts[$role->id] ?? 0);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Role::class);

        $data = $request->validate([
            'name'          => ['required', 'string', 'regex:/^[a-z][a-z0-9-]*$/', 'max:125', 'unique:roles,name'],
            'permissions'   => ['sometimes', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        $role = DB::transaction(function () use ($data) {
            $role = Role::create([
                'name'       => $data['name'],
                'guard_name' => 'web',
            ]);

            $role->syncPermissions($data['permissions'] ?? []);

            return $role;
        });

        $role->load('permissions');
        $this->attachUserCounts([$role]);

        return (new RoleResource($role))->response()->setStatusCode(201);
    }

    public function update(Request $request, Role $role): JsonResponse|RoleResource
    {
        $this->authorize('update', $role);

        // System role names are referenced by literal string in code (e.g.
        // AuthController::register, policies). Block renames before validation
        // so the admin gets a precise message instead of a generic 422.
        if (
            SystemRoles::isSystem($role)
            && $request->has('name')
            && $request->input('name') !== $role->name
        ) {
            return response()->json([
                'message' => 'System role names cannot be changed.',
                'role'    => $role->name,
            ], 409);
        }

        $data = $request->validate([
            'name'          => [
                'sometimes',
                'string',
                'regex:/^[a-z][a-z0-9-]*$/',
                'max:125',
                'unique:roles,name,' . $role->id,
            ],
            'permissions'   => ['sometimes', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        DB::transaction(function () use ($data, $role) {
            if (array_key_exists('name', $data) && ! SystemRoles::isSystem($role)) {
                $role->update(['name' => $data['name']]);
            }

            if (array_key_exists('permissions', $data)) {
                $role->syncPermissions($data['permissions']);
            }
        });

        $role->load('permissions');
        $this->attachUserCounts([$role]);

        return new RoleResource($role);
    }

    public function destroy(Role $role): JsonResponse
    {
        $this->authorize('delete', $role);

        if (SystemRoles::isSystem($role)) {
            return response()->json([
                'message' => 'System roles cannot be deleted.',
                'role'    => $role->name,
            ], 409);
        }

        $this->attachUserCounts([$role]);
        $usersCount = (int) $role->users_count;

        if ($usersCount > 0) {
            return response()->json([
                'message'     => 'Cannot delete a role currently assigned to users.',
                'users_count' => $usersCount,
            ], 409);
        }

        $role->delete();

        return response()->json(null, 204);
    }
}
