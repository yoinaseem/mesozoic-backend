<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'name'               => $this->name,
            'email'              => $this->email,
            'roles'              => $this->getRoleNames(),
            // Effective permissions = role-derived ∪ direct grants.
            'permissions'        => $this->getAllPermissions()->pluck('name'),
            // Direct grants only (excludes role-derived). Lets the admin UI
            // distinguish between "granted via role" and "granted directly".
            'direct_permissions' => $this->getDirectPermissions()->pluck('name'),
            // Hotel-manager pivot — empty array for users without that role.
            'managed_hotel_ids'  => $this->managedHotels()->pluck('hotels.id'),
            'created_at'         => $this->created_at,
            'updated_at'         => $this->updated_at,
        ];
    }
}
