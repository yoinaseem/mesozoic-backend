<?php

namespace App\Http\Resources;

use App\Support\SystemRoles;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'guard_name'  => $this->guard_name,
            'is_system'   => SystemRoles::isSystem($this->resource),
            'users_count' => $this->when(
                $this->resource->users_count !== null,
                fn () => (int) $this->resource->users_count,
            ),
            'permissions' => $this->whenLoaded(
                'permissions',
                fn () => $this->permissions->pluck('name'),
            ),
            'created_at'  => $this->created_at,
            'updated_at'  => $this->updated_at,
        ];
    }
}
