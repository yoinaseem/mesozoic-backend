<?php

namespace App\Http\Controllers;

use App\Http\Resources\PermissionResource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PermissionController extends Controller
{
    use AuthorizesRequests;

    public function index(): AnonymousResourceCollection
    {
        // Listing the catalogue is a read on the same admin surface as roles,
        // so we gate it through RolePolicy::viewAny to avoid a separate policy.
        $this->authorize('viewAny', Role::class);

        return PermissionResource::collection(
            Permission::query()->orderBy('name')->get(),
        );
    }
}
