<?php

namespace App\Support;

use Spatie\Permission\Models\Role;

/**
 * Catalogue of role names that are seeded by RolesAndPermissionsSeeder and
 * referenced by literal name in code (e.g. AuthController::register assigns
 * 'customer'). System roles cannot be renamed or deleted at runtime — their
 * permission sets remain editable so admins can tighten or loosen each role's
 * scope without breaking the references.
 */
final class SystemRoles
{
    public const NAMES = [
        'superadmin',
        'hotel-manager',
        'ferry-manager',
        'park-manager',
        'beach-manager',
        'customer',
    ];

    public static function isSystem(Role $role): bool
    {
        return in_array($role->name, self::NAMES, true);
    }
}
