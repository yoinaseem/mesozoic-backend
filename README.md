# Mesozoic Isle — Backend

Laravel 12 API for the Mesozoic Isle hotel/ferry/park/beach management system.

## Authorization (RBAC)

Authentication is handled by Laravel Sanctum (bearer tokens). Authorization
layers **spatie/laravel-permission** on top: a role-based permission check at
the route level, plus per-resource policies for scoped rules (e.g. "this
hotel-manager only edits hotels they're assigned to").

### Roles

| Role            | Intended for                                                                                                                                                             |
| --------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `superadmin`    | Full access to everything; can create/delete any resource.                                                                                                               |
| `hotel-manager` | Manages a specific set of hotels (via `hotel_user` pivot) — can view/update their hotels and do full CRUD on their room types and rooms. Cannot create or delete hotels. |
| `ferry-manager` | View/update ferry resources (module pending).                                                                                                                            |
| `park-manager`  | View/update park resources (module pending).                                                                                                                             |
| `beach-manager` | View/update beach resources (module pending).                                                                                                                            |
| `customer`      | Default role for self-registered users. No management permissions.                                                                                                       |

### Permissions catalogue

Flat `resource.action` strings seeded by `RolesAndPermissionsSeeder`:

- **Hotels:** `hotels.{view,create,update,delete}`
- **Room types:** `room-types.{view,create,update,delete}`
- **Rooms:** `rooms.{view,create,update,delete}`
- **Users:** `users.{view,create,update,delete}`
- **Ferry (pending):** `ferry.{view,create,update,delete}`
- **Park (pending):** `park.{view,create,update,delete}`
- **Beach (pending):** `beach.{view,create,update,delete}`
- **Bookings (pending):** `bookings.{view,create,update,delete,cancel}`

Mutations on protected routes are guarded with `permission:<name>` middleware.
Where a rule depends on a specific resource instance (e.g. hotel-manager must
manage _this_ hotel), the `HotelPolicy` / `RoomPolicy` / `RoomTypePolicy` /
`UserPolicy` perform the per-instance check.

### Scoped hotel assignments

Hotel-managers are linked to specific hotels via the `hotel_user` pivot table.
Use `$user->managedHotels` or `$user->managesHotel($hotel)` to inspect
assignments. Superadmins bypass the scope check entirely (every policy's
`before()` method returns `true` for them).

### Dev account credentials

`DevUsersSeeder` runs automatically via `php artisan migrate:fresh --seed` in
the `local` and `testing` environments. **Each account's password is its own
email address** (e.g. `superadmin@mesozoic.test` logs in with password
`superadmin@mesozoic.test`) — makes copy/paste during manual API testing and
frontend demos painless.

| Email                         | Role            | Notes                                          |
| ----------------------------- | --------------- | ---------------------------------------------- |
| `superadmin@mesozoic.test`    | `superadmin`    | Full access.                                   |
| `hotel-manager@mesozoic.test` | `hotel-manager` | Assigned to "Mesozoic Grand Hotel" (hotel #1). |
| `ferry-manager@mesozoic.test` | `ferry-manager` |                                                |
| `park-manager@mesozoic.test`  | `park-manager`  |                                                |
| `beach-manager@mesozoic.test` | `beach-manager` |                                                |
| `customer@mesozoic.test`      | `customer`      | Default role for self-registration.            |

Production environments seed the roles/permissions catalogue only — no fixture
users are created.

### Register / login / me response shape

`/api/auth/register`, `/api/auth/login`, and `/api/auth/me` all return a
`UserResource` payload including `roles` (array of role names) and
`permissions` (flat array of permission strings). Frontend clients should read
those to drive UI gating. Newly-registered users are assigned the `customer`
role automatically.
