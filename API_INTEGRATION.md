### Mesozoic Backend — Frontend Integration Spec

This document describes every HTTP endpoint the Laravel API currently exposes, the auth model, response shapes, validation rules, and authorization rules. It is the contract for a Next.js client.

---

#### 1. Conventions

- **Base URL**: `${NEXT_PUBLIC_API_URL}/api` (e.g. `http://localhost:8000/api`).
- **Format**: JSON in, JSON out. Always send `Accept: application/json` so Laravel returns JSON for validation/auth errors instead of redirecting.
- **CSRF**: not required — this is a token-auth API (Sanctum personal access tokens, not the SPA cookie flow).
- **Auth header**: `Authorization: Bearer <token>` for any route inside the `auth:sanctum` group.
- **Pagination**: list endpoints return Laravel's standard paginator envelope (`data`, `links`, `meta`) at **10 items/page**. Override page with `?page=2`.
- **Timestamps**: ISO-8601 strings (`2026-04-19T13:24:01.000000Z`).
- **Dates**: `YYYY-MM-DD` (e.g. `activity_date`, `travel_date`, `arrival_date`).
- **Times**: `HH:mm:ss` 24-hour (e.g. `09:30:00`). Validators reject anything else.
- **App timezone**: `Indian/Maldives` (UTC+5, no DST) since DESD-95. Server-side `today()`, `now()`, and date filters resolve to local business day. Timestamps in API responses serialize in this zone — clients consuming ISO strings with timezone info handle this transparently; clients hard-coding a UTC offset on display will need updating. Date-only fields (`date`, `activity_date`, `travel_date`) are unaffected.
- **Money**: decimal strings cast at the model level; `BeachActivityResource` casts `price` to `float`. Treat as numeric, not string-equal.
- **Soft-delete model (hotel stack + users).** `DELETE` on `hotels`, `room-types`, `rooms`, and `users` is **archival** — rows are hidden from index/show but preserved for historical booking references. Guarded by upcoming-booking checks (see `409` below). Restore endpoints exist for each (see §5, §6, §3). Bookings themselves are NOT soft-deletable at the row level — they use a separate `status='cancelled'` + `cancelled_at` soft-cancel (see §11–§15).
- **Errors**:
  - `401 Unauthorized` — missing/invalid bearer token on a protected route.
  - `403 Forbidden` — token valid but the policy or `permission:` middleware denies the action.
  - `404 Not Found` — route-model binding failure (including nested-scope mismatches, e.g. `/hotels/1/rooms/9` where room 9 belongs to hotel 2). Archived hotels/rooms/room-types/users also resolve as 404 on their standard read endpoints.
  - `409 Conflict` — archival blocked because upcoming confirmed bookings reference the entity. Default body shape: `{ "message": "...", "blocking_bookings": <int> }`. Also raised by:
    - `POST .../restore` when a parent is still archived.
    - **Park hour-cascade endpoints** (§9 Opening Hours / Hour Overrides) when a mutation would invalidate existing schedules and the request did not pass `on_conflict: "cascade"`. Body shape: `{ "message", "conflicts": [{schedule_id, park_activity_id, date, start_time, end_time, confirmed_bookings}], "counts": {schedules, bookings} }`.
    - **Day-pass cancel / date-change** (§12) when the reservation holds confirmed park-activity bookings on the affected park-date. Default `blocking_bookings` shape applies.
    - **Park-capacity lower** (§9 Theme Parks) when a child activity's `max_capacity` exceeds the new cap. Body shape: `{ "message", "offending_activities": [{id, name, max_capacity}] }`.
  - `422 Unprocessable Entity` — validation. Body shape: `{ "message": "...", "errors": { "field": ["msg"] } }`.
  - `204 No Content` — successful delete/archive/cancel.

---

#### 2. Auth & Session

| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST | `/auth/register` | public | Register a new customer. Auto-assigns the `customer` role. |
| POST | `/auth/login` | public | Exchange credentials for a Sanctum token. |
| POST | `/auth/logout` | bearer | Revoke the current token only (other devices keep working). |
| GET  | `/auth/me` | bearer | Return the authenticated user. |
| GET  | `/auth/me/hotels` | bearer | Return hotels the caller has admin access to — for scoped dropdowns. |

##### POST `/auth/register`
Request:
```json
{
  "name": "Ada Lovelace",
  "email": "ada@example.com",
  "password": "supersecret",
  "password_confirmation": "supersecret"
}
```
Validation: `name` required ≤255, `email` required + unique, `password` required ≥8 + `confirmed` (so `password_confirmation` is mandatory).

Response `201`:
```json
{
  "token": "1|plain-text-sanctum-token...",
  "user": { /* UserResource — see §3 */ }
}
```

##### POST `/auth/login`
Request: `{ "email": "...", "password": "..." }`. On bad creds returns `422` with `errors.email` (not 401). **Archived users** (soft-deleted via `DELETE /users/{id}`) are invisible to the User model's global scope, so login against their email returns the same `422 errors.email` as an unknown account — there's no separate "account archived" signal. Restore the user (§3) to re-enable login.
Response `200`: same shape as register.

##### POST `/auth/logout`
No body. Response `200`: `{ "message": "Logged out successfully." }`. Only the token used in the request is revoked.

##### GET `/auth/me`
Response `200`: bare `UserResource` (no envelope).

##### GET `/auth/me/hotels`
Thin endpoint for populating scoped hotel dropdowns on admin pages (e.g. the Hotel filter on the Bookings page). Behaviour by role:

- `superadmin` → every live hotel (archived hotels excluded).
- `hotel-manager` → rows via the `hotel_user` pivot. Empty array if they hold the role but have no assignments yet.
- anyone else (e.g. `customer`) → empty array.

Response `200`:
```json
{
  "data": [
    { "id": 1, "name": "Mesozoic Grand Hotel" },
    { "id": 2, "name": "Triassic Bay Resort" }
  ]
}
```
Ordered by `name` ASC. Only `id` and `name` are returned — if the UI needs the rest of the hotel record, fetch `GET /hotels/{id}` on selection.

##### Frontend session pattern
- Persist the token (e.g. `httpOnly` cookie set by a Next.js Route Handler, or `localStorage` if the app is purely client-rendered).
- On boot, hit `/auth/me` to hydrate the user. If it 401s, clear the token and redirect to login.
- The `user.roles` and `user.permissions` arrays are the source of truth for client-side gating (hide buttons, route guards). Always re-check on the server, since the policy will reject anyway.

---

#### 3. User Resource

`UserResource` shape (returned by all auth + user endpoints):
```json
{
  "id": 12,
  "name": "Ada Lovelace",
  "email": "ada@example.com",
  "roles": ["customer"],
  "permissions": ["hotels.view", "..."],
  "created_at": "2026-04-19T13:24:01.000000Z",
  "updated_at": "2026-04-19T13:24:01.000000Z"
}
```
`roles` is the Spatie role-name array. `permissions` is the flattened list of all permissions the user has via roles + direct grants — use this for client-side capability checks.

##### Users CRUD (admin)
All under `/users`, all behind `auth:sanctum`. Authorization is by `UserPolicy`:
- Superadmin → full access (via `before()`).
- Anyone else: `view`/`update` allowed **only on their own record**; list/create/delete/restore denied.

| Method | Path | Who can call |
|---|---|---|
| GET    | `/users`        | superadmin (archived users hidden) |
| GET    | `/users/{user}` | superadmin OR self (archived users return 404) |
| POST   | `/users`        | superadmin |
| PUT/PATCH | `/users/{user}` | superadmin OR self |
| DELETE | `/users/{user}` | superadmin (soft-delete / archive) |
| POST   | `/users/{user}/restore` | superadmin (un-archives) |

Notes:
- `POST /users` does **not** auto-assign a role (unlike `/auth/register`). If you create users via this endpoint, role assignment is a separate concern not currently exposed.
- `PATCH /users/{me}` accepts partial `{ name?, email?, password? + password_confirmation }`. Self-update is intended for profile editing.
- `DELETE /users/{user}` is **archival**. Returns `409 { blocking_bookings: N }` when the user has any confirmed `RoomBooking` with `check_out_date >= today` (across any of their reservations). Ticket bookings — park/beach/ferry/park-activity — are time-locked to a confirmed room stay at creation, so the room-booking check transitively covers them too. On success returns `204`; the row stays in the DB with `deleted_at` set, historical bookings continue to render `reservation.user`, and the user can no longer log in.
- **`users.email` stays uniquely constrained at the DB level even for archived rows.** Re-registering under an archived account's email is deliberately blocked — surfaces as `422 errors.email` on `/auth/register` or `POST /users`. Restore the archived user (or pick a different email) to un-block.
- `POST /users/{user}/restore` resolves the archived row (route uses `->withTrashed()`), un-archives it, and returns the `UserResource` with `200`. Log-in works again immediately.

---

#### 4. Roles & Permissions Catalogue

Roles seeded by `RolesAndPermissionsSeeder`:

| Role | Module scope | Notes |
|---|---|---|
| `superadmin` | everything | `before()` short-circuits every policy. |
| `hotel-manager` | hotels they're assigned to (`hotel_user` pivot) | Has `hotels.view`, `hotels.update`, full CRUD on `room-types` and `rooms` of those hotels, plus `bookings.view/create/update/delete/cancel`. Cannot create/delete hotels. |
| `ferry-manager` | all ferries | Has `ferry.view`, `ferry.create`, `ferry.update`, plus `bookings.view/update/cancel` for `/ferry-bookings`. |
| `park-manager` | all parks + park bookings | Has `park.view`, `park.create`, `park.update`, plus `bookings.view/update/cancel` for `/park-bookings` and `/park-activity-bookings`. **Does not** have `bookings.create` (customers purchase their own day passes and activity tickets). |
| `beach-manager` | all beach activities | Has `beach.view`, `beach.create`, `beach.update`, plus `bookings.view/update/cancel` for `/beach-bookings`. |
| `customer` | self-booking only | Default role on self-register. Has `bookings.view` and `bookings.create`. **Does not have `bookings.cancel`** — customer-initiated cancellation was removed across every booking module; cancel calls return `403` at the middleware layer. Customer-facing UX is "contact staff" for every booking type. |

Permission strings are `resource.action` (e.g. `hotels.update`, `beach.create`). The full seeded list lives in `database/seeders/RolesAndPermissionsSeeder.php`. Use `user.permissions` from `/auth/me` to drive UI; the server is authoritative.

**Cancellation is staff-only, everywhere.** All five booking types (room, park, beach, park-activity, ferry) route their `DELETE` through `bookings.cancel`, which is held by the relevant manager role + superadmin only. A customer hitting any booking `DELETE` gets `403`. UI should show a "contact staff to cancel" note instead of a cancel button on every booking list.

##### Dev fixture accounts (only seeded in `local`/`testing`)
Password = email, for every account:
- `superadmin@mesozoic.test`
- `hotel-manager@mesozoic.test` (assigned to the seeded "Mesozoic Grand Hotel")
- `ferry-manager@mesozoic.test`
- `park-manager@mesozoic.test`
- `beach-manager@mesozoic.test`
- `customer@mesozoic.test`

---

#### 5. Hotels

`HotelResource`:
```json
{
  "id": 1,
  "name": "Mesozoic Grand Hotel",
  "address": "1 Tyrannosaurus Way, Isla Nublar",
  "description": "...",
  "amenities": ["wifi", "pool"],
  "image": "https://.../cover.jpg",
  "room_types": [ /* RoomTypeResource[] only on `show` */ ],
  "created_at": "...",
  "updated_at": "..."
}
```
`room_types` is included only on `GET /hotels/{hotel}` (controller eager-loads it).

| Method | Path | Auth | Permission | Policy gate |
|---|---|---|---|---|
| GET    | `/hotels`        | public | — | archived hotels hidden |
| GET    | `/hotels/{hotel}` | public | — | archived hotel → 404 |
| POST   | `/hotels`        | bearer | `hotels.create` | superadmin only (`HotelPolicy::create` returns false for everyone else, so only `before()` lets it through). |
| PUT/PATCH | `/hotels/{hotel}` | bearer | `hotels.update` | superadmin OR (`hotels.update` AND assigned to that hotel via `hotel_user` pivot). |
| DELETE | `/hotels/{hotel}` | bearer | `hotels.delete` | superadmin only. **Soft-delete / archive** (see below). |
| POST   | `/hotels/{hotel}/restore` | bearer | `hotels.delete` | superadmin only. Route uses `->withTrashed()` binding so archived hotels still resolve. |

**Archive semantics.** `DELETE /hotels/{id}` now refuses with `409 { blocking_bookings: N }` when any confirmed `RoomBooking` tied to the hotel has `check_out_date >= today`. On success, returns `204` and:
- The hotel + its room-types + rooms all receive a matching `deleted_at` timestamp (cascade via `Hotel::deleting` event).
- The hotel disappears from `GET /hotels` and `GET /hotels/{id}`.
- Historical `GET /room-bookings/{id}` responses whose booking references the archived hotel **still render** the `hotel` block — booking reads eager-load with `withTrashed()`. Frontends may see a hotel on a booking detail that no longer appears on the hotel list; that's expected.

**Restore.** `POST /hotels/{id}/restore` un-archives the hotel and cascade-restores all currently-trashed room-types and rooms under it. Returns `200` with the `HotelResource`. If no archive window exists on that hotel it's effectively a no-op.

Validation:

`POST /hotels` (all required unless noted):
```
name        string max:255 required
address     string max:255 required
description string required
amenities   array  nullable; each item string
image       string max:255 nullable  // URL
```

`PUT/PATCH /hotels/{hotel}`: same fields, all `sometimes` (partial update). `address`, `description`, `amenities`, `image` may be `null`.

---

#### 6. Room Types & Rooms (nested under hotels)

Both resources are scoped under `/hotels/{hotel}/...` with `scopeBindings()`, so the nested ID must belong to the parent hotel or you'll get `404`.

##### Room Types

`RoomTypeResource`:
```json
{
  "id": 5,
  "hotel_id": 1,
  "name": "Deluxe Sea View",
  "description": "...",
  "image": "https://.../room.jpg",
  "capacity": 2,
  "price": "350.00",
  "amenities": ["wifi", "balcony"],
  "rooms_count": 12,           // present only when withCount('rooms') was applied
  "created_at": "...",
  "updated_at": "..."
}
```
`rooms_count` is included on `index`, `show`, `store` (set to 0), and `update`.

| Method | Path | Auth | Permission middleware | Policy |
|---|---|---|---|---|
| GET    | `/hotels/{hotel}/room-types` | public | — | — |
| GET    | `/hotels/{hotel}/room-types/{room_type}` | public | — | — |
| POST   | `/hotels/{hotel}/room-types` | bearer | `room-types.create\|room-types.update\|room-types.delete` | `room-types.create` AND user manages hotel. |
| PUT/PATCH | `/hotels/{hotel}/room-types/{room_type}` | bearer | (any of the three) | `room-types.update` AND user manages hotel. |
| DELETE | `/hotels/{hotel}/room-types/{room_type}` | bearer | (any of the three) | `room-types.delete` AND user manages hotel. **Soft-delete / archive.** |
| POST   | `/hotels/{hotel}/room-types/{room_type}/restore` | bearer | `room-types.delete` | `room-types.delete` AND user manages hotel. Refuses with `409` if the parent hotel is still archived. |

**Archive semantics.** `DELETE` refuses with `409 { blocking_bookings: N }` when any confirmed `RoomBooking` with `room_type_id = this` has `check_out_date >= today`. On success: `204`, the room-type + all its rooms receive a matching `deleted_at` (cascade via `RoomType::deleting`). `GET` on an archived row returns `404`. `rooms_count` on `RoomTypeResource` reflects *live* rooms only (SoftDeletes global scope on `withCount`).

**Restore.** Restores the room-type and cascade-restores its currently-trashed rooms. Returns `409` if the parent hotel is still archived — restore the hotel first (which cascades back to both anyway).

Note: the route middleware uses `permission:a|b|c` (OR), then the policy enforces the per-verb permission. So as a frontend you only need to know "the user has at least one room-types.* permission" to render the section; per-button checks should still consult the specific permission.

Validation `POST` (all `nullable` except `name`):
```
name        string max:255 required
description string nullable
image       string max:255 nullable
capacity    integer min:1 nullable
price       numeric min:0 nullable
amenities   array nullable; each item string
```
`PATCH`: same fields with `sometimes`.

##### Rooms

`RoomResource`:
```json
{
  "id": 42,
  "hotel_id": 1,
  "room_type_id": 5,
  "room_no": "204",
  "room_type": { /* RoomTypeResource — eager-loaded on every endpoint */ },
  "created_at": "...",
  "updated_at": "..."
}
```

| Method | Path | Auth | Permission middleware | Policy |
|---|---|---|---|---|
| GET    | `/hotels/{hotel}/rooms` | public | — | — |
| GET    | `/hotels/{hotel}/rooms/{room}` | public | — | — |
| POST   | `/hotels/{hotel}/rooms` | bearer | `rooms.create\|rooms.update\|rooms.delete` | `rooms.create` AND manages hotel. |
| PUT/PATCH | `/hotels/{hotel}/rooms/{room}` | bearer | (any) | `rooms.update` AND manages hotel. |
| DELETE | `/hotels/{hotel}/rooms/{room}` | bearer | (any) | `rooms.delete` AND manages hotel. **Soft-delete / archive.** |
| POST   | `/hotels/{hotel}/rooms/{room}/restore` | bearer | `rooms.delete` | `rooms.delete` AND manages hotel. Refuses with `409` if parent hotel OR parent room-type is still archived. |

**Archive semantics.** `DELETE` refuses with `409 { blocking_bookings: N }` when any confirmed `RoomBooking` with `room_id = this` has `check_out_date >= today`. On success: `204`, row kept with `deleted_at` set; `GET` returns `404`. `RoomAvailability` (powering `/hotels/{id}/availability` and booking creation) excludes archived rooms automatically via the SoftDeletes global scope.

**`room_no` uniqueness is now validation-layer, not a DB constraint.** Enforced via `Rule::unique('rooms')->where('hotel_id', …)->whereNull('deleted_at')`. Concretely: archiving room `101` frees up that number, so a new live `101` can be created in the same hotel. Creating a duplicate *live* `101` returns `422 errors.room_no`.

Validation `POST`:
```
room_type_id required, must exist in room_types AND belong to {hotel}
room_no      string max:255 required, unique within {hotel} (live rooms only)
```
`PATCH`: same with `sometimes`; uniqueness ignores the current room id AND archived siblings.

---

#### 7. Ferry Types, Ferries & Ferry Schedules

**Slot-shape redesign (DESD-100).** A `FerrySchedule` is now a **fixed slot** — a recurring departure window like "morning ferry 09:00→11:00", not a per-date row. One row per `(ferry_id, departure_time) WHERE deleted_at IS NULL`. The slot carries `departure_time + arrival_time + departure_port + arrival_port`. **No `travel_date`, no `arrival_date`, no per-trip `status`.** Customers pick a `(slot, date)` pair at booking time (§15); the date lives on `FerryBooking.travel_date`.

Domain shape:

- **`FerryType`** — catalogue (`name + description + image + capacity + price`). Price and capacity live here.
- **`Ferry`** — a physical vessel under a type (`ferry_type_id + name`). Inherits price + capacity from its type.
- **`FerrySchedule`** — a recurring slot for a vessel (`ferry_id + departure_time + arrival_time + departure_port + arrival_port`). Overnight crossings are encoded as `arrival_time < departure_time`.

All ferry-side mutations are gated by `ferry.create | ferry.update | ferry.delete` — `ferry-manager` and `superadmin` can mutate vessels, types, and slots.

**Soft-delete + cascade.** All three resources soft-delete (`deleted_at`); `DELETE` on any of them is **archival** with a hybrid 409 / `on_conflict=cascade` flow (mirrors DESD-95 / DESD-97). When confirmed bookings exist downstream, the default response is `409 {message, blocking_bookings}`; passing `on_conflict=cascade` in the request body cancels those bookings (`status=cancelled`, `cancelled_at=now()`), soft-deletes the row + descendants, and returns `200 {cascade: {…counts}}`. Empty-cascade deletes return `204` for back-compat. Soft-deleted slots vacate their `(ferry_id, departure_time)` pair so an operator can recreate a slot at the same time after archiving the original.

##### Ferry Types

`FerryTypeResource`:
```json
{
  "id": 3,
  "name": "Air Conditioned",
  "description": "AC saloon ferry",
  "image": null,
  "capacity": 80,
  "price": 60.0,
  "ferries": [ /* FerryResource[] only on `show` */ ],
  "ferries_count": 2,        // present on `show`
  "created_at": "...",
  "updated_at": "..."
}
```

| Method | Path | Auth | Permission | Policy |
|---|---|---|---|---|
| GET    | `/ferry-types` | public | — | — |
| GET    | `/ferry-types/{ferry_type}` | public | — | includes `ferries` + `ferries_count` |
| POST   | `/ferry-types` | bearer | `ferry.create\|ferry.update\|ferry.delete` | `ferry.create` |
| PUT/PATCH | `/ferry-types/{ferry_type}` | bearer | (any) | `ferry.update` |
| DELETE | `/ferry-types/{ferry_type}` | bearer | (any) | superadmin only (`FerryTypePolicy::delete` returns false) — archive + cascade |

Validation `POST`:
```
name        string max:255 required
description string nullable
image       string max:255 nullable
capacity    integer min:1 required
price       numeric min:0 required
```
`PATCH`: every field `sometimes`.

`DELETE` body:
```
on_conflict  optional, in:reject,cascade  (default: reject)
```
With confirmed bookings on any vessel of this type:
- Default → `409 {message, blocking_bookings}`.
- `on_conflict=cascade` → `200 {cascade: {ferries_archived, slots_archived, bookings_cancelled}}` after archiving the type, every ferry under it, every slot under those ferries, and cancelling all confirmed bookings.

Empty cascade → `204`.

##### Ferries (vessels)

`FerryResource`:
```json
{
  "id": 9,
  "ferry_type_id": 3,
  "name": "Isla Express II",
  "ferry_type": { /* FerryTypeResource — present when eager-loaded */ },
  "schedules": [ /* FerryScheduleResource[] only on `show` */ ],
  "schedules_count": 5,      // present on `show`
  "created_at": "...",
  "updated_at": "..."
}
```

The vessel itself only carries `name` and the FK. Price and capacity live on the linked type — read them through `ferry.ferry_type`.

| Method | Path | Auth | Permission | Policy |
|---|---|---|---|---|
| GET    | `/ferries` | public | — | — |
| GET    | `/ferries/{ferry}` | public | — | includes `ferry_type` + `schedules` + `schedules_count` |
| POST   | `/ferries` | bearer | `ferry.create\|ferry.update\|ferry.delete` | `ferry.create` |
| PUT/PATCH | `/ferries/{ferry}` | bearer | (any) | `ferry.update` |
| DELETE | `/ferries/{ferry}` | bearer | (any) | superadmin only — archive + cascade |

Validation `POST`:
```
ferry_type_id  required, exists:ferry_types
name           string max:255 required, unique per (ferry_type_id, name)
```
`PATCH`: both fields `sometimes`; the composite-unique check still applies, ignoring the current ferry's id. If `ferry_type_id` is omitted on PATCH, the check uses the row's existing type. Duplicate `(ferry_type_id, name)` returns `422` with `errors.name` — the DB has a matching unique index as a backstop, but clients will only ever see the 422.

`DELETE` body:
```
on_conflict  optional, in:reject,cascade  (default: reject)
```
With confirmed bookings on any of this ferry's slots:
- Default → `409 {message, blocking_bookings}`.
- `on_conflict=cascade` → `200 {cascade: {slots_archived, bookings_cancelled}}` after archiving the ferry + all its slots and cancelling those bookings.

Empty cascade → `204`.

##### Ferry Schedules (slots — DESD-100)

A `FerrySchedule` is a recurring departure slot on a vessel. `FerryScheduleResource`:

```json
{
  "id": 22,
  "ferry_id": 9,
  "departure_time": "09:00:00",
  "arrival_time": "11:00:00",
  "departure_port": "Mainland",
  "arrival_port": "Isla Nublar",
  "ferry": { /* FerryResource when eager-loaded */ },
  "created_at": "...",
  "updated_at": "..."
}
```

| Method | Path | Auth | Permission |
|---|---|---|---|
| GET  | `/ferry-schedules` | public, paginated, includes `ferry` | — |
| GET  | `/ferry-schedules/{ferry_schedule}` | public, includes `ferry` | — |
| GET  | `/ferries/{ferry}/schedules` | public, paginated | — |
| POST | `/ferry-schedules` | bearer | `ferry.create` |
| PUT/PATCH | `/ferry-schedules/{ferry_schedule}` | bearer | `ferry.update` |
| DELETE | `/ferry-schedules/{ferry_schedule}` | bearer | `ferry.delete` (superadmin) — archive + cascade |

Validation `POST`:
```
ferry_id        required, exists:ferries
departure_time  required, H:i:s, unique per (ferry_id, departure_time) WHERE deleted_at IS NULL
arrival_time    required, H:i:s, different:departure_time
departure_port  required, string max:255
arrival_port    required, string max:255
```
Slot-uniqueness is enforced inside a `DB::transaction` with `lockForUpdate` on the parent `Ferry` so two concurrent slot-creates can't both pass the check. The DB-level partial unique `ferry_schedule_unique_slot` is the race-safe backstop.

**Overnight crossings** are encoded as `arrival_time < departure_time` (Carbon overnight wrap on read). Same-time arrival/departure returns `422 errors.arrival_time`.

`PATCH`: every field `sometimes`; slot-uniqueness check ignores the current row and falls back to the row's stored `ferry_id` when the field is omitted. Same `arrival != departure` check on effective values.

`DELETE` body:
```
on_conflict  optional, in:reject,cascade  (default: reject)
```
With confirmed bookings on this slot:
- Default → `409 {message, blocking_bookings}`.
- `on_conflict=cascade` → `200 {cascade: {bookings_cancelled}}` after cancelling those bookings + soft-deleting the slot.

Empty cascade → `204`. Soft-deleted slots are hidden from index/show but stay readable through booking eager-loads (`schedule` uses `withTrashed()` so cancelled-booking history keeps serializing the slot it ran against).

---

#### 8. Beach Activities & Schedules

`BeachActivityResource`:
```json
{
  "id": 3,
  "name": "Sunset Snorkel",
  "description": "...",
  "price": 75.0,             // float (resource casts to (float))
  "capacity": 12,
  "duration": 90,            // minutes (integer, required on create)
  "image": "https://.../snorkel.jpg",
  "schedules": [ /* present on `show` */ ],
  "schedules_count": 4,      // present on `show`
  "created_at": "...",
  "updated_at": "..."
}
```

`BeachActivityScheduleResource`:
```json
{
  "id": 17,
  "beach_activity_id": 3,
  "activity_date": "2026-05-04",
  "start_time": "09:30:00",
  "end_time": "11:00:00",   // canonical (Model B) since DESD-97 — both stored on the schedule row, NOT NULL
  "status": "pending",       // pending | confirmed | cancelled
  "activity": { /* BeachActivityResource if eager-loaded */ },
  "created_at": "...",
  "updated_at": "..."
}
```

##### Beach Activities

| Method | Path | Auth | Permission | Policy |
|---|---|---|---|---|
| GET    | `/beach-activities` | public | — | — |
| GET    | `/beach-activities/{beach_activity}` | public | — | includes `schedules` + `schedules_count` |
| POST   | `/beach-activities` | bearer | `beach.create` | `beach.create` |
| PUT/PATCH | `/beach-activities/{beach_activity}` | bearer | `beach.update` | `beach.update` |
| DELETE | `/beach-activities/{beach_activity}` | bearer | `beach.delete` | superadmin only (`BeachActivityPolicy::delete` returns false otherwise) |

Validation `POST`:
```
name        string max:255 required
description string nullable
price       numeric min:0 required
capacity    integer min:1 required
duration    integer min:1 required   // minutes — UI default for new schedules only since DESD-97; never consumed at read time
image       string max:255 nullable
```
`PATCH`: same with `sometimes`.

**`duration` is a UI default since DESD-97.** Mutating an activity's `duration` no longer shifts existing schedules — schedule `start_time` / `end_time` are canonical (Model B). Treat `duration` as a prefill hint when authoring new schedules.

##### Beach Activity Schedules (nested)

Path prefix: `/beach-activities/{beach_activity}/schedules`. Scope-bound, so the schedule must belong to the parent activity.

| Method | Path | Auth | Permission middleware | Policy |
|---|---|---|---|---|
| GET    | `…/schedules` | public | — | — |
| GET    | `…/schedules/{schedule}` | public | — | — |
| POST   | `…/schedules` | bearer | `beach.create\|beach.update\|beach.delete` | `beach.create` |
| PUT/PATCH | `…/schedules/{schedule}` | bearer | (any) | `beach.update` |
| DELETE | `…/schedules/{schedule}` | bearer | (any) | superadmin only |

Validation `POST`:
```
activity_date  required, date, after_or_equal:today                      (DESD-97)
start_time     required, H:i:s
end_time       required, H:i:s, different:start_time                     (DESD-97: canonical column, NOT NULL)
status         optional, in:pending,confirmed,cancelled  (default: pending)
```

**Server-side business rules** (each returns `422` with the listed key):
- **Slot uniqueness** (`errors.start_time`): no other live (non-cancelled) schedule of this activity has the same `(activity_date, start_time)`. Cancelled rows logically vacate their slot — a new live schedule can take a cancelled slot back.
- **No overlap** (`errors.start_time`, DESD-97): no other live schedule of the same activity overlaps `[start_time, end_time)`. Half-open intervals (back-to-back schedules don't conflict). Date-aware overnight math: an `end < start` schedule wraps to `date+1`, so a `D-1` overnight schedule is correctly compared against a `D` early-morning candidate.

`PATCH`: every field `sometimes`. The slot-uniqueness and overlap checks re-run when any of `activity_date` / `start_time` / `end_time` changes. **Past-date guard (DESD-97)**: `activity_date` carries `after_or_equal:today` on update too; status / notes updates on past schedules remain allowed when `activity_date` is omitted from the payload.

**Slot uniqueness (DESD-97).** Enforced at three layers: (1) overlap check via `BeachScheduleReconciler`, which rejects any `[start, end)` overlapping another live schedule of the same activity (a strict superset of exact-match duplicates); (2) controller-level closure-uniqueness check inside the `DB::transaction` + `lockForUpdate` critical section, retained for a friendlier 422 message on exact slot collisions; (3) Postgres partial unique index `beach_activity_schedule_unique_slot` on `(beach_activity_id, activity_date, start_time) WHERE status <> 'cancelled'` — the race-safe DB backstop. A new schedule can reuse the slot of a cancelled schedule because the index excludes them; live duplicates and overlaps still return `422 errors.start_time`.

**Race-safety (DESD-97).** Booking and schedule writes run inside `DB::transaction` with `lockForUpdate()` on the parent (schedule for bookings, activity for schedules) so concurrent submissions can't both pass count-then-insert capacity / uniqueness checks.

---

#### 9. Theme Parks, Opening Hours, Overrides, Activities & Activity Schedules

A `ThemePark` is an on-island venue with a weekday-baseline schedule (`ParkOpeningHour`), per-date exceptions (`ParkHourOverride`), and a catalogue of activities (`ParkActivity`) that run on scheduled sessions (`ParkActivitySchedule`). All park-side reads are **public**; mutations are gated by `park.create | park.update | park.delete` — held by `park-manager` and `superadmin`. Park bookings (§12) and park activity bookings (§14) walk this chain for open-day checks and coupling rules.

The database currently holds a single park, but the controllers and uniqueness rules are already multi-park-ready — adding a second park is additive.

##### Theme Parks (flat resource)

`ThemeParkResource`:
```json
{
  "id": 1,
  "name": "Mesozoic Theme Park",
  "images": ["https://.../cover.jpg"],
  "description": "...",
  "capacity": 5000,
  "price": 50.0,
  "contact_email": "contact@mesozoic.test",
  "contact_phone": "+960-000-0000",
  "opening_hours": [ /* ParkOpeningHourResource[] — only on `show` */ ],
  "activities":    [ /* ParkActivityResource[] — only on `show`; each carries `schedules_count` */ ],
  "created_at": "...",
  "updated_at": "..."
}
```

| Method | Path | Auth | Permission | Policy |
|---|---|---|---|---|
| GET    | `/theme-parks` | public | — | archived parks hidden |
| GET    | `/theme-parks/{theme_park}` | public | — | archived park → 404; includes `opening_hours` + `activities` |
| POST   | `/theme-parks` | bearer | `park.create` | park-manager / superadmin |
| PUT/PATCH | `/theme-parks/{theme_park}` | bearer | `park.update` | park-manager / superadmin |
| DELETE | `/theme-parks/{theme_park}` | bearer | `park.delete` | superadmin only. **Soft-delete / archive** (see below). |
| POST   | `/theme-parks/{theme_park}/restore` | bearer | `park.delete` | superadmin only. Route uses `->withTrashed()` so archived parks still resolve. |

**Archive semantics.** `DELETE /theme-parks/{id}` refuses with `409 { blocking_bookings: N }` when **either** path has an upcoming confirmed booking: a `ParkBooking` with `park_id = this` and `date >= today`, OR a `ParkActivityBooking` with a schedule under any of this park's activities where `schedule.date >= today`. `blocking_bookings` is the sum across both paths. On success: `204`, and the park + all its `park_activities` + their `park_activity_schedules` receive a matching `deleted_at` (cascade via `ThemePark::deleting`). `park_opening_hours` and `park_hour_overrides` are **not** soft-deleted — they're config rows and become unreachable via public routes when the park is archived (route-model binding 404s on the trashed park). Historical `GET /park-bookings/{id}` and `GET /park-activity-bookings/{id}` still render their archived `park` / `schedule.park_activity.theme_park` blocks via `withTrashed()` eager-loads.

**Restore.** `POST /theme-parks/{id}/restore` un-archives the park and cascade-restores every trashed `park_activity` + `park_activity_schedule` under it. Returns `200` with the `ThemeParkResource`.

Validation `POST`:
```
name          required, string max:255
images        nullable, array; each item string max:2048
description   required, string
capacity      required, integer min:1
price         required, numeric min:0
contact_email required, email max:255
contact_phone required, string max:50
```
`PATCH`: every field `sometimes`.

**Capacity-lower guard (DESD-95).** If `PATCH` lowers `capacity` below the `max_capacity` of any existing `ParkActivity` under this park, the request is rejected with `409 { message, offending_activities: [{id, name, max_capacity}] }`. Operator must lower the offending activities' `max_capacity` first (or archive them). The symmetric check on activity create/update (`max_capacity ≤ park.capacity`) lives at §9 Park Activities.

##### Opening Hours (weekday baseline — nested)

`ParkOpeningHour` is the default schedule for a weekday. One row per `(park_id, day)`; `day ∈ {monday, tuesday, wednesday, thursday, friday, saturday, sunday}`. Both times required.

```json
{
  "id": 5,
  "park_id": 1,
  "day": "monday",
  "open_time": "09:00:00",
  "close_time": "18:00:00",
  "created_at": "...",
  "updated_at": "..."
}
```

| Method | Path | Auth | Permission |
|---|---|---|---|
| GET    | `/theme-parks/{theme_park}/opening-hours` | public, paginated | — |
| GET    | `/theme-parks/{theme_park}/opening-hours/{opening_hour}` | public | — |
| POST   | `/theme-parks/{theme_park}/opening-hours` | bearer | `park.create` |
| PUT/PATCH | `.../opening-hours/{opening_hour}` | bearer | `park.update` |
| DELETE | `.../opening-hours/{opening_hour}` | bearer | `park.delete` |

Validation `POST`:
```
day          required, in:monday,…,sunday, unique per park_id
open_time    required, H:i:s
close_time   required, H:i:s, different:open_time
on_conflict  optional, in:reject,cascade (default: reject)
```
`PATCH`: every field `sometimes`; unique check ignores the current row. Controller also re-validates `open_time != close_time` on effective values.

**Hours-cascade (DESD-95).** Baseline create / update / delete may invalidate pre-existing `ParkActivitySchedule` rows whose stored window no longer fits the new effective hours over the next 365 days (matches the `effective-hours` endpoint's visible horizon — `today` through `today + 365` inclusive). Two modes:

- **`on_conflict: "reject"`** (default): if any conflicts exist, the mutation is rolled back and returns `409` with the structured conflict report from §1. No state change.
- **`on_conflict: "cascade"`**: mutation persists + the reconciler cancels affected schedules and their confirmed `ParkActivityBooking` rows in one transaction. **Re-sync exception for `is_all_day` activities**: if the date is still open under the new hours, the all-day schedule's window is *re-synced* to the new hours instead of cancelled (bookings stay confirmed). Successful response includes `cascade: { schedules_cancelled, bookings_cancelled, schedules_resynced }`.

`DELETE` returns `204` with no body when no cascade was needed (back-compat) and `200` with the cascade summary when schedules were cancelled. For `DELETE`, pass the mode as a query param: `?on_conflict=cascade`.

##### Hour Overrides (per-date exceptions — nested)

`ParkHourOverride` wins over the weekday baseline for one specific `date`. Setting both times to `null` means **"closed that day"** (e.g. sudden holiday). One row per `(park_id, date)`.

```json
{
  "id": 11,
  "park_id": 1,
  "date": "2026-12-25",
  "open_time": null,
  "close_time": null,
  "is_closed": true,            // convenience flag — true when both times are null
  "note": "Closed for holiday",
  "created_at": "...",
  "updated_at": "..."
}
```

| Method | Path | Auth | Permission |
|---|---|---|---|
| GET    | `/theme-parks/{theme_park}/hour-overrides` | public, paginated, ordered by `date` | — |
| GET    | `.../hour-overrides/{hour_override}` | public | — |
| POST   | `.../hour-overrides` | bearer | `park.create` |
| PUT/PATCH | `.../hour-overrides/{hour_override}` | bearer | `park.update` |
| DELETE | `.../hour-overrides/{hour_override}` | bearer | `park.delete` |

Validation `POST`:
```
date         required, date, unique per park_id
open_time    nullable, required_with:close_time, H:i:s
close_time   nullable, required_with:open_time, H:i:s, different:open_time
note         nullable, string max:255
on_conflict  optional, in:reject,cascade (default: reject)
```
Both times set → "open with explicit hours". Both `null` → "closed that day". Partial (one set, one null) → `422`. `PATCH`: every field `sometimes`; the both-or-neither check runs on effective values.

**Hours-cascade (DESD-95).** Override create / update / delete may invalidate pre-existing `ParkActivitySchedule` rows on the affected date(s) — single date for create and delete, union of old + new dates for update if the `date` field changes. Same hybrid contract as Opening Hours: default `reject` returns `409` with the conflict report; `cascade` persists the mutation and chains into reconciler cleanup (cancel timed schedules + their bookings; re-sync `is_all_day` schedules whose date stays open). Closed-day overrides (`open_time=null`, `close_time=null`) cancel all schedules on that date under cascade. Successful response includes `cascade: { schedules_cancelled, bookings_cancelled, schedules_resynced, ferry_bookings_cancelled }`. `DELETE` returns `204` when no cascade was needed and `200` with the cascade summary when schedules were cancelled; pass the mode as a query param: `?on_conflict=cascade`. Two delete cases that *can* narrow hours and surface a `409`: an override broader than baseline (e.g. extended-hours event day) and the only override on a park with no baseline configured (deleting it makes the date `not_configured`).

**Ferry-booking cascade (DESD-100).** The same cascade flow also reaches confirmed ferry bookings. If the override / baseline change makes a date park-closed (closed-day override, deletion of the override on a `not_configured` date, or a baseline edit that turns a date closed), confirmed `FerryBooking` rows on that date are reported in the `409` payload's `ferry_bookings: [{id, reservation_id, ferry_schedule_id, travel_date, guests}]` block (alongside `counts.ferry_bookings`) and, under `on_conflict=cascade`, get flipped to `status=cancelled` with `cancelled_at=now()`. The cascade summary on success carries `ferry_bookings_cancelled`. Ferries care about open-vs-closed only — narrowing the open window from `09:00–18:00` to `12:00–14:00` does **not** cascade-cancel ferry bookings.

##### Effective Hours (computed endpoint)

`GET /theme-parks/{theme_park}/effective-hours` resolves the actual schedule for a date or a range using **override > baseline > `not_configured`** precedence. This is the endpoint (and the `ThemePark::effectiveHoursOn` model method behind it) park bookings use for `isOpenOn(date)`.

Query params (exactly one form required):
- `?date=YYYY-MM-DD` — single day
- `?from=YYYY-MM-DD&to=YYYY-MM-DD` — inclusive range, max 366 days

Response — `data` is always an array (one entry per day):
```json
{
  "data": [
    {
      "date": "2026-04-25",
      "status": "open",           // open | closed | not_configured
      "source": "baseline",        // baseline | override | null
      "open_time": "09:00:00",
      "close_time": "18:00:00",
      "note": null
    }
  ]
}
```
`status: "not_configured"` means neither an override nor a weekday baseline exists — callers should treat this as "closed" for booking purposes. Validation returns `422` if neither form is supplied, if `from` is passed without `to` (or vice versa), if `to < from`, or if the range exceeds 366 days.

##### Park Activities (nested)

`ParkActivity` is a catalogue entry under one park. Each activity is either **all-day** (`is_all_day = true`, `duration = null`) or **timed** (`is_all_day = false`, `duration` in minutes required). `max_capacity` caps concurrent guests per session. Sessions themselves are `ParkActivitySchedule` rows.

**`is_all_day` semantics (DESD-95).** "Drop-in any time during park hours" — aquarium-style day pass. One schedule per `(activity, date)`; the schedule's window mirrors `effectiveHoursOn(date)` at materialization time. **Schedules for all-day activities are not authored manually** — the booking flow auto-creates them on first booking (see §14 for the alternate `park_activity_id + date` POST shape). Manual `POST .../schedules` for an all-day activity returns `422 errors.park_activity_id`. Hours-change cascade (§9) re-syncs the all-day schedule's window when the date stays open and only cancels when the date becomes closed.

**`duration` is a UI default, not a runtime invariant (DESD-95).** Mutating `activity.duration` no longer shifts existing schedules — schedule `start_time`/`end_time` are canonical (Model B). Treat `duration` as a prefill hint when authoring new timed schedules.

```json
{
  "id": 3,
  "park_id": 1,
  "name": "Jurassic River Rafting",
  "description": "...",
  "price": 35.0,
  "image": null,
  "duration": 45,               // minutes, or null when is_all_day=true
  "max_capacity": 20,
  "is_all_day": false,
  "schedules":       [ /* ParkActivityScheduleResource[] — only on `show` */ ],
  "schedules_count": 12,         // only on `show`
  "created_at": "...",
  "updated_at": "..."
}
```

| Method | Path | Auth | Permission |
|---|---|---|---|
| GET    | `/theme-parks/{theme_park}/activities` | public, paginated | — |
| GET    | `/theme-parks/{theme_park}/activities/{park_activity}` | public | includes `schedules` + `schedules_count` |
| POST   | `.../activities` | bearer | `park.create` |
| PUT/PATCH | `.../activities/{park_activity}` | bearer | `park.update` |
| DELETE | `.../activities/{park_activity}` | bearer | `park.delete` — **soft-delete / archive** (superadmin only) |
| POST   | `.../activities/{park_activity}/restore` | bearer | `park.delete` — superadmin only; `409` if parent park is still archived |

**Archive semantics.** `DELETE` refuses with `409 { blocking_bookings: N }` when any confirmed `ParkActivityBooking` references a schedule under this activity with `schedule.date >= today`. On success: `204`, activity + all its schedules receive a matching `deleted_at` (cascade via `ParkActivity::deleting`). Archived activities are hidden from the public list and return `404` on direct fetch; `schedules_count` reflects live schedules only.

**Restore.** Restores the activity and cascade-restores its currently-trashed schedules. Returns `409` if the parent park is still archived — restore the park first (that cascades back through the whole subtree anyway).

Validation `POST`:
```
name         required, string max:255
description  nullable, string
price        required, numeric min:0
image        nullable, string max:255
duration     required unless is_all_day=true, integer min:1  (forced to null when is_all_day=true)
max_capacity required, integer min:1, ≤ park.capacity (DESD-95)
is_all_day   optional, boolean (default false)
```
`PATCH`: every field `sometimes`. Controller re-checks on effective values: if `is_all_day` ends up `false` and `duration` ends up `null`, returns `422` on `duration`. `max_capacity > park.capacity` returns `422 errors.max_capacity` on both create and update — see §9 Theme Parks for the symmetric park-side guard.

##### Park Activity Schedules (double-nested)

`ParkActivitySchedule` pins a `ParkActivity` to `date + start_time + end_time`. `status ∈ {scheduled, cancelled, completed}` (default `scheduled`). Since DESD-95, `start_time` and `end_time` are both `NOT NULL` and **canonical (Model B)** — `activity.duration` is a UI default only, never consumed at read time. The resource still emits `end_time_source` for backward compatibility, always `'explicit'` now (the `'derived'` and `null` modes have been removed; the field will be retired in a follow-up release).

Overnight schedules are supported: `end_time < start_time` is interpreted as next-calendar-day. Stored verbatim; the date-aware reconciler handles the wrap.

```json
{
  "id": 9,
  "park_activity_id": 3,
  "date": "2026-05-01",
  "start_time": "10:00:00",
  "end_time": "10:45:00",
  "end_time_source": "explicit",  // always "explicit" since DESD-95
  "status": "scheduled",
  "notes": null,
  "activity": { /* ParkActivityResource when eager-loaded */ },
  "created_at": "...",
  "updated_at": "..."
}
```

| Method | Path | Auth | Permission |
|---|---|---|---|
| GET    | `/theme-parks/{theme_park}/activities/{park_activity}/schedules` | public, paginated | — |
| GET    | `.../schedules/{schedule}` | public | — |
| POST   | `.../schedules` | bearer | `park.create\|park.update\|park.delete` |
| PUT/PATCH | `.../schedules/{schedule}` | bearer | (any) |
| DELETE | `.../schedules/{schedule}` | bearer | (any) — **soft-delete / archive** (superadmin only per policy) |
| POST   | `.../schedules/{schedule}/restore` | bearer | (any) — superadmin only; `409` if park OR activity is still archived |

Route named `park-activity-schedules`. Pipe-OR middleware is safe here because per-verb enforcement lives in `ParkActivitySchedulePolicy`.

**Archive semantics.** `DELETE` refuses with `409 { blocking_bookings: N }` when any confirmed `ParkActivityBooking` has `park_activity_schedule_id = this` and `schedule.date >= today`. On success: `204`, row kept with `deleted_at` set; `GET` returns `404` once archived.

**Slot uniqueness (DESD-95).** Enforced at three layers: (1) reconciler-level overlap check on every create / window-mutation, which rejects any `[start, end)` overlapping an existing live schedule of the same activity (a strict superset of exact-match duplicates) — see `errors.start_time`; (2) controller-level closure-uniqueness check inside the `DB::transaction` + `lockForUpdate` critical section, retained for friendlier error messages; (3) Postgres partial unique index `(park_activity_id, date, start_time) WHERE deleted_at IS NULL AND status <> 'cancelled'`, the race-safe DB backstop. A new schedule can reuse the `(date, start_time)` slot of an archived **or cancelled** schedule because the index predicate excludes both; live duplicates and overlaps still return `422 errors.start_time`.

**Race-safety (DESD-95).** Booking and schedule writes run inside `DB::transaction` with `lockForUpdate()` on the parent (park / schedule / activity respectively) so concurrent submissions can't both pass count-then-insert capacity / uniqueness checks. The pre-PR scenario where two simultaneous bookings could both succeed at the capacity edge is closed.

**Restore.** Returns `409` if the parent park or the parent activity is still archived — restore the nearest archived ancestor first; that cascades back through.

**Manual creation forbidden for `is_all_day` activities (DESD-95).** `POST .../schedules` returns `422 errors.park_activity_id` when the activity is `is_all_day=true`. Use the booking flow's `park_activity_id + date` shape (§14) — the schedule materializes automatically on first booking with its window mirrored from `effectiveHoursOn(date)`.

Validation `POST`:
```
date        required, date, after_or_equal:today          (DESD-95)
start_time  required, H:i:s
end_time    required, H:i:s, different:start_time         (DESD-95: now required + NOT NULL column)
status      optional, in:scheduled,cancelled,completed
notes       nullable, string
```
**Server-side business rules** (each returns `422` with the listed key):
- **Fits-in-hours** (`errors.start_time`): the `[start_time, end_time)` window must fit inside `effectiveHoursOn(date)`. Date-aware overnight math: `end_time < start_time` wraps to date+1, same for park hours where `close < open`. Closed / `not_configured` dates are fail-closed (`errors.date`).
- **No overlap** (`errors.start_time`): no other live (non-trashed, non-cancelled) schedule of the same activity overlaps `[start_time, end_time)`. Skipped for `is_all_day` activities (which use per-date uniqueness, materialized via the booking flow).

`PATCH`: every field `sometimes`. The fits-in-hours and overlap checks re-run when any of `date` / `start_time` / `end_time` changes. **Past-date guard (DESD-95)**: changing `date` to a value before today returns `422 errors.date`; tweaks to `notes` / `status` on past schedules remain allowed for cleanup. `start_time != end_time` is re-checked on effective values.

---

#### 10. Reservations

A `Reservation` is the trip envelope that groups a user's bookings together: one or more `RoomBooking`s (§11) plus tickets from the four ticket modules (`ParkBooking`, `BeachBooking`, `ParkActivityBooking`, `FerryBooking` — §12–15). Reservations are **created implicitly** — there is no `POST /reservations`. They come into being as a side-effect of `POST /room-bookings` when the caller omits `reservation_id`; subsequent ticket bookings attach to the reservation by passing its id.

A reservation has no dates, status, or totals of its own — those are derived from attached bookings. The one piece of business logic it exposes is the seat-pool helper that ticket modules call to size how many tickets the reservation can hold on a given date:

- **`Reservation::seatPoolOn($date)`** — sum of confirmed room-booking `guests` with **exclusive-checkout** window (`check_in_date <= $date < check_out_date`). Used by park, beach, and park-activity bookings. Checkout day is always rejected (pool = 0) because guests are leaving.
- **`Reservation::ferrySeatPoolOn($date)`** — same, but **inclusive** on both ends (`check_in_date <= $date <= check_out_date`). Used only by ferry bookings (§15) because arrival-day and departure-day ferries are primary use cases.

Both helpers ignore `cancelled` room bookings, so a cancellation zeroes out the seat pool for those dates.

`ReservationResource`:
```json
{
  "id": 42,
  "user_id": 17,
  "user":          { /* UserResource when eager-loaded */ },
  "room_bookings": [ /* RoomBookingResource[] when eager-loaded */ ],
  "created_at": "...",
  "updated_at": "..."
}
```

Ticket bookings (park / beach / activity / ferry) are **not** embedded on the reservation payload; fetch them from their respective index endpoints with `?reservation_id=…`.

| Method | Path | Auth | Permission | Policy |
|---|---|---|---|---|
| GET | `/reservations` | bearer | `bookings.view` | customer: own only; hotel-manager: reservations whose room-bookings touch a managed hotel; superadmin: all |
| GET | `/reservations/{reservation}` | bearer | `bookings.view` | same scope as index (hotel-manager access is via `managedHotels` ↔ `roomBookings.hotel_id`) |

No `POST / PATCH / DELETE` — reservations are not directly mutable. Attach new rooms via `POST /room-bookings`; manage status on the underlying booking rows.

Index: paginated (10/page, `created_at DESC`). Eager-loads `user`, `roomBookings.hotel`, `roomBookings.roomType`, `roomBookings.room`.

---

#### 11. Room Bookings

A `RoomBooking` is one reserved room in a hotel for a `[check_in_date, check_out_date)` window. It belongs to a `Reservation` (auto-created if absent) and owns a specific `room_id` picked at store time. `status ∈ {confirmed, cancelled}` (no `pending` — rows are confirmed on create).

**Reservation resolution.** `POST /room-bookings` accepts an optional `reservation_id`:
- **Omitted** → a fresh `Reservation` is created for the caller and attached.
- **Supplied** → the reservation must belong to the caller, or the caller must be `superadmin` (who can attach to any reservation). Failure returns `422` on `reservation_id` with `"This reservation does not belong to you."`

**Room allocation.** Clients do **not** send `room_id` on create — pick happens server-side. The controller chooses the lowest-numbered room of the requested `room_type_id` not already booked on overlapping confirmed dates. If the room-type has zero aggregate capacity left for the window, the request fails with `422` on `room_type_id`: `"No rooms of this type are available for the selected dates."` There is a fallback: if no per-room conflicts exist but aggregate capacity is still sufficient, the first room of the type is assigned.

**Availability check.** Delegated to `RoomAvailability::forRoomType`, the same service that backs `GET /hotels/{hotel}/availability` — the endpoints stay in lockstep. Known limitation: count-then-insert; a concurrent create could slip through the window. Acceptable for current scope.

**Pricing.** `price_per_night = roomType.price`, `nights = check_out_date − check_in_date` (exclusive), `total_price = bcmul(price_per_night, nights, 2)`.

**Update semantics.** `PATCH` supports `status`, `check_in_date`, `check_out_date`, `guests`, and `room_id` (explicit manager override). Date changes trigger a fresh availability check (ignoring this booking's id), recompute `nights + total_price`, and auto-reassign a room if the current one is no longer free on the new dates. An explicit `room_id` must refer to a room in the same hotel **and** the same room type; a busy room returns `422` on `room_id` with `"This room is already booked for the selected dates."` A `guests` value above `roomType.capacity` returns `422` on `guests`.

**Cancellation is staff-only.** `DELETE` is a soft cancel: `status = cancelled`, `cancelled_at = now()`, returns `204`. Only `hotel-manager` (of the booking's hotel) and `superadmin` can cancel. Customers calling `DELETE` get `403` — the customer role no longer holds `bookings.cancel`. Customer-facing UI should show a "contact staff to cancel" note, not a cancel button.

**Seat-pool invariant.** Both `PATCH` and `DELETE` call `enforceSeatPoolInvariantOrFail($reservation)`. It is currently a **deliberate no-op** — kept in place so the ticket-booking cancellation cascade has a hook when it ships. Today, cancelling a room booking does not auto-cancel attached park/beach/activity/ferry tickets; that's a documented gap (see `CLAUDE.md`).

`RoomBookingResource`:
```json
{
  "id": 88,
  "reservation_id": 42,
  "hotel_id": 1,
  "room_type_id": 3,
  "room_id": 104,
  "status": "confirmed",          // confirmed | cancelled
  "check_in_date": "2026-05-01",
  "check_out_date": "2026-05-04",
  "guests": 2,
  "price_per_night": "150.00",
  "nights": 3,
  "total_price": "450.00",
  "cancelled_at": null,
  "reservation": { /* when eager-loaded */ },
  "hotel":       { /* when eager-loaded */ },
  "room_type":   { /* when eager-loaded */ },
  "room":        { /* when eager-loaded */ },
  "created_at": "...",
  "updated_at": "..."
}
```

| Method | Path | Auth | Permission | Policy |
|---|---|---|---|---|
| GET    | `/room-bookings` | bearer | `bookings.view` | customer: own reservation only; hotel-manager: bookings in a managed hotel; superadmin: all |
| GET    | `/room-bookings/{room_booking}` | bearer | `bookings.view` | owner, hotel-manager of that hotel, or superadmin |
| POST   | `/room-bookings` | bearer | `bookings.create` | any holder (ownership enforced on `reservation_id` when supplied) |
| PUT/PATCH | `/room-bookings/{room_booking}` | bearer | `bookings.update` | hotel-manager of the booking's hotel, or superadmin — **customer cannot PATCH** |
| DELETE | `/room-bookings/{room_booking}` | bearer | `bookings.cancel` | hotel-manager of the booking's hotel, or superadmin — **customer cannot DELETE** |

Routes are split **per-verb** (not `apiResource` + pipe-OR) because `customer` holds `bookings.view | create` but not `bookings.update` / `bookings.cancel`; a pipe-OR declaration would let PATCH/DELETE through the middleware and only get rejected at the policy layer.

**Archived parent resolution.** `hotel`, `room_type`, `room`, and `reservation.user` on this resource are all eager-loaded with `withTrashed()`. A historical booking whose hotel / room / room-type / customer has since been archived still renders those nested blocks in full — the row acts as an append-only audit trail.

Index filters (AND-combined): `?status=confirmed|cancelled`, `?hotel_id=`, `?room_type_id=`, `?reservation_id=`, `?check_in_from=YYYY-MM-DD`, `?check_in_to=YYYY-MM-DD`. Date bounds are inclusive on `check_in_date`; if both are provided, `check_in_to` must be `>=` `check_in_from` or the request fails with `422` on `check_in_to`. No max-range cap — admin use. Pagination 10/page, ordered `check_in_date DESC`.

Validation `POST`:
```
reservation_id  optional, nullable, exists:reservations  (omit = auto-create for caller)
room_type_id    required, exists:room_types
check_in_date   required, date, after_or_equal:today
check_out_date  required, date, after:check_in_date
guests          required, integer, min:1
```

Validation `PATCH` (every field `sometimes`):
```
status          in:confirmed,cancelled
check_in_date   date
check_out_date  date, after:check_in_date
guests          integer, min:1
room_id         nullable, must exist in same (hotel_id, room_type_id) as the booking
```

Swapping `hotel_id` or `room_type_id` on `PATCH` is not supported — treat those as immutable. To move the guest to a different hotel or type, cancel and rebook.

---

#### 12. Park Bookings

A park booking is a day-pass admission ticket tied to a `Reservation` (the trip envelope containing one or more room bookings). One booking covers `guests` people admitted to `park` on `date`.

The guest count is validated against `Reservation::seatPoolOn(date)` — the sum of confirmed room-booking guests active on that date with **exclusive checkout** (`check_in_date <= date < check_out_date`). This lets a single booking cover a group spread across multiple rooms (e.g. a family of 6 across 3 rooms buys one 6-guest day pass). It also means the check-out date always has a seat pool of 0 and is rejected.

Prerequisite: the caller's reservation must already have at least one confirmed `RoomBooking` (§11) covering `date`, and `park.isOpenOn(date) === true` (§9 effective hours).

`ParkBookingResource`:
```json
{
  "id": 42,
  "reservation_id": 7,
  "park_id": 1,
  "date": "2026-05-02",
  "guests": 6,
  "status": "confirmed",       // confirmed | cancelled
  "price_per_guest": "45.00",
  "total_price": "270.00",
  "cancelled_at": null,
  "reservation": { /* ReservationResource when eager-loaded */ },
  "park": { /* ThemeParkResource when eager-loaded */ },
  "created_at": "...",
  "updated_at": "..."
}
```

| Method | Path | Auth | Permission | Policy |
|---|---|---|---|---|
| GET    | `/park-bookings` | bearer | `bookings.view` | customer sees only their own; park-manager + superadmin see all |
| GET    | `/park-bookings/{park_booking}` | bearer | `bookings.view` | owner, park-manager, or superadmin |
| POST   | `/park-bookings` | bearer | `bookings.create` | superadmin, or customer attaching to their own reservation |
| PUT/PATCH | `/park-bookings/{park_booking}` | bearer | `bookings.update` | park-manager or superadmin (customers cannot PATCH) |
| DELETE | `/park-bookings/{park_booking}` | bearer | `bookings.cancel` | park-manager or superadmin only — **customer cannot DELETE** |

Routes are wired **per-verb** (not pipe-OR) because `customer` has `bookings.create|view` but not `bookings.update` / `bookings.cancel` — a pipe-OR would leak `PATCH` / `DELETE` to customers at the middleware layer before the policy could deny it.

Index filters (all optional, AND-combined): `?status=confirmed|cancelled`, `?park_id=`, `?reservation_id=`, `?date=YYYY-MM-DD`. Pagination 10/page.

Validation `POST`:
```
reservation_id  required, exists:reservations
park_id         required, exists:theme_parks
date            required, date, after_or_equal:today
guests          required, integer, min:1
```

Additional server-side business rules enforced in `ParkBookingController::store`, each returning `422` with a specific validation key on failure:

- **Ownership** (`errors.reservation_id`): reservation must belong to the caller (superadmin / park-manager bypass).
- **Seat pool > 0** (`errors.date`): `Reservation::seatPoolOn(date) > 0` — at least one confirmed room active. Check-out date is excluded.
- **Seat pool cap** (`errors.guests`): `guests <= seatPoolOn(date)`. Partial-group visits (`guests < seatPoolOn`) are allowed.
- **Park open** (`errors.date`): `ThemePark::isOpenOn(date) === true` (override > baseline > `not_configured` → closed).
- **Uniqueness** (`errors.date`): no existing confirmed `ParkBooking` for the same `(reservation_id, park_id, date)`. Cancelled rows don't block — cancel, then rebook. Uniqueness is per-park, so the same reservation could hold passes for two different parks on the same date once more parks exist.
- **Capacity** (`errors.park_id`): `Σ confirmed guests on (park_id, date) + guests <= park.capacity`.

On create, `price_per_guest = park.price` and `total_price = park.price × guests` are derived server-side (any client-submitted values for these fields are ignored). Created rows default to `status="confirmed"`.

Validation `PATCH`:
```
status  sometimes, in:confirmed,cancelled
date    sometimes, date
guests  sometimes, integer, min:1
```
If `date` or `guests` change, the seat-pool, open-on-date, uniqueness, and capacity checks are re-run against the new values. `total_price` is recomputed when `guests` changes (same `bcmul(price_per_guest × guests, 2)` formula). Setting `status=cancelled` also sets `cancelled_at = now()`.

`DELETE` — soft cancel (staff-only):
- Sets `status=cancelled` and `cancelled_at=now()`; returns `204` with no body. The row is preserved for audit.
- Customers receive `403` — park booking cancellation is now staff-only (`park-manager` / `superadmin`), unified with the room/beach/ferry/park-activity modules.

**Day-pass cancel / date-change block (DESD-95).** Both `PATCH status=cancelled` (or `DELETE`) and `PATCH date=…` are blocked with `409 { message, blocking_bookings: <int> }` when the same reservation holds confirmed `ParkActivityBooking` rows on a schedule of this park on the day-pass's current `date`. Closes the orphan-bookings gap: pre-DESD-95, cancelling a day-pass left dependent activity bookings silently confirmed even though the `assertHoldsDayPass` invariant was broken. Operator must cancel the activity bookings first, then the day-pass.

**Cancellation cascade (known gap).** Cancelling a `RoomBooking` that drops `seatPoolOn(date)` to 0 does **not** auto-cancel attached park bookings today. Treat this on the client as a potential stale-ticket risk until the cascade lands. Tracked as a follow-up against `RoomBookingController::enforceSeatPoolInvariantOrFail`.

**Race-safety (DESD-95).** Park-booking `store` and the date/guests path of `update` run inside `DB::transaction` + `lockForUpdate()` on the `theme_parks` row, with the duplicate-per-park and capacity checks inside the lock. Two concurrent `POST /park-bookings` requests at the capacity edge can no longer both succeed; one gets `422 errors.park_id`. Same pattern in `/park-activity-bookings` (§14) with the lock on the schedule row.

**Multi-park note.** The database currently holds a hard limit of one `ThemePark`. The controller and uniqueness rule are already multi-park-ready — when more parks are added, the per-park uniqueness key supports splits (parents at Park A, kids at Park B on the same date) without code changes.

---

#### 13. Beach Bookings

A beach booking is a session ticket tied to a single `BeachActivitySchedule` (the specific `activity_date + start_time` slot of a `BeachActivity`). One booking covers `guests` people for that slot. The booking date is derived from the schedule — there is no separate `date` column on the booking.

Same reservation-tie rule as park bookings: the caller's `Reservation` must have a confirmed `RoomBooking` covering the schedule's `activity_date`, and `guests` must fit inside `Reservation::seatPoolOn(activity_date)`. Exclusive checkout applies, so a booking on the room's check-out date is rejected.

**Cancellation is staff-only** (unified across every booking module — `beach-manager` / `superadmin` only). Customer `DELETE` returns `403` at the middleware layer because the customer role does not hold `bookings.cancel`.

`BeachBookingResource`:
```json
{
  "id": 31,
  "reservation_id": 7,
  "beach_activity_schedule_id": 12,
  "guests": 2,
  "status": "confirmed",       // confirmed | cancelled
  "price_per_guest": "75.00",
  "total_price": "150.00",
  "cancelled_at": null,
  "reservation": { /* ReservationResource when eager-loaded */ },
  "schedule":    { /* BeachActivityScheduleResource when eager-loaded (includes `activity`) */ },
  "created_at": "...",
  "updated_at": "..."
}
```

| Method | Path | Auth | Permission | Policy |
|---|---|---|---|---|
| GET    | `/beach-bookings` | bearer | `bookings.view` | customer sees only their own; beach-manager + superadmin see all |
| GET    | `/beach-bookings/{beach_booking}` | bearer | `bookings.view` | owner, beach-manager, or superadmin |
| POST   | `/beach-bookings` | bearer | `bookings.create` | superadmin, or customer attaching to their own reservation |
| PUT/PATCH | `/beach-bookings/{beach_booking}` | bearer | `bookings.update` | beach-manager or superadmin (customers cannot PATCH) |
| DELETE | `/beach-bookings/{beach_booking}` | bearer | `bookings.cancel` | beach-manager or superadmin only (customers cannot cancel) |

Routes are wired **per-verb** (not pipe-OR) — same reasoning as `/park-bookings`.

Index filters (all optional, AND-combined): `?status=confirmed|cancelled`, `?beach_activity_schedule_id=`, `?beach_activity_id=`, `?reservation_id=`, `?date=YYYY-MM-DD`. Pagination 10/page.

Validation `POST`:
```
reservation_id              required, exists:reservations
beach_activity_schedule_id  required, exists:beach_activity_schedules
guests                      required, integer, min:1
```

Additional server-side business rules enforced in `BeachBookingController::store`, each returning `422` with a specific validation key on failure:

- **Ownership** (`errors.reservation_id`): reservation must belong to the caller (superadmin / beach-manager bypass).
- **Schedule bookable** (`errors.beach_activity_schedule_id`): schedule `status` is not `cancelled`, and `activity_date` is not in the past.
- **Seat pool > 0** (`errors.beach_activity_schedule_id`): `Reservation::seatPoolOn(activity_date) > 0` — at least one confirmed room covers the date.
- **Seat pool cap** (`errors.guests`): `guests <= seatPoolOn(activity_date)`.
- **Uniqueness** (`errors.beach_activity_schedule_id`): no existing confirmed `BeachBooking` for the same `(reservation_id, beach_activity_schedule_id)`. Cancelled rows don't block — staff can cancel, customer can rebook.
- **Capacity** (`errors.beach_activity_schedule_id`): `Σ confirmed guests on the schedule + guests <= activity.capacity`.

On create, `price_per_guest = activity.price` and `total_price = activity.price × guests` are derived server-side. Created rows default to `status="confirmed"` (no pending state — if capacity allows and every rule passes, the booking confirms immediately).

Validation `PATCH`:
```
status  sometimes, in:confirmed,cancelled
guests  sometimes, integer, min:1
```
If `guests` changes, seat-pool and capacity checks are re-run inside the schedule lock. `total_price` is recomputed. Setting `status=cancelled` (from a non-cancelled state) also sets `cancelled_at = now()`. The schedule is not swappable on PATCH — cancel and re-book to change slots.

**Reconfirming a cancelled booking (DESD-97 hotfix).** Setting `status=confirmed` on a previously-cancelled booking re-runs the duplicate + capacity checks inside the schedule lock and clears `cancelled_at` back to null. If another confirmed booking already exists for the same `(reservation, schedule)` pair (because the customer rebooked after the cancel), the response is `422 errors.status` rather than the 500 the partial unique would otherwise produce. Operator must cancel the rebook first, then reconfirm the original.

`DELETE` — soft cancel (staff-only):
- Sets `status=cancelled` and `cancelled_at=now()`; returns `204` with no body. Row preserved for audit.
- A customer call returns `403` — only `beach-manager` / `superadmin` pass the policy.

**Confirmed-booking duplicate index (DESD-97).** A Postgres partial unique on `beach_bookings (reservation_id, beach_activity_schedule_id) WHERE status='confirmed'` backs the controller-level duplicate check race-safely. Cancelled rows are excluded so re-book after staff-cancel still works.

**Cancellation cascade** — same known gap as park bookings: cancelling the underlying `RoomBooking` does not auto-cancel attached beach bookings today.

---

#### 14. Park Activity Bookings

A park activity booking is a session ticket tied to a single `ParkActivitySchedule` (which pins a `ParkActivity` to a date + start_time within a `ThemePark`). One booking covers `guests` people for that session. Booking date is derived from `schedule.date` — no separate `date` column.

**Coupling rule — prerequisite `ParkBooking` required.** Unlike beach bookings, a park activity booking requires the caller's `Reservation` to already hold a confirmed `ParkBooking` (day-pass) for the same park on the same date. The activity-booking guest count must also be ≤ the day-pass guest count. Rationale: you need park admission to attend an activity inside it.

The standard seat-pool rule still applies in addition to the coupling rule (guard against stale tickets if a room-booking cancellation left a day-pass orphaned — see known gap below).

**Cancellation is staff-only** (unified across every booking module — `park-manager` / `superadmin` only).

`ParkActivityBookingResource`:
```json
{
  "id": 54,
  "reservation_id": 7,
  "park_activity_schedule_id": 18,
  "guests": 2,
  "status": "confirmed",       // confirmed | cancelled
  "price_per_guest": "30.00",
  "total_price": "60.00",
  "cancelled_at": null,
  "reservation": { /* ReservationResource when eager-loaded */ },
  "schedule":    { /* ParkActivityScheduleResource when eager-loaded (includes `activity` + park) */ },
  "created_at": "...",
  "updated_at": "..."
}
```

| Method | Path | Auth | Permission | Policy |
|---|---|---|---|---|
| GET    | `/park-activity-bookings` | bearer | `bookings.view` | customer sees only their own; park-manager + superadmin see all |
| GET    | `/park-activity-bookings/{park_activity_booking}` | bearer | `bookings.view` | owner, park-manager, or superadmin |
| POST   | `/park-activity-bookings` | bearer | `bookings.create` | superadmin, or customer attaching to their own reservation |
| PUT/PATCH | `/park-activity-bookings/{park_activity_booking}` | bearer | `bookings.update` | park-manager or superadmin (customers cannot PATCH) |
| DELETE | `/park-activity-bookings/{park_activity_booking}` | bearer | `bookings.cancel` | park-manager or superadmin only (customers cannot cancel) |

Routes are per-verb (not pipe-OR) — same reasoning as `/park-bookings` and `/beach-bookings`.

Index filters (all optional, AND-combined): `?status=confirmed|cancelled`, `?park_activity_schedule_id=`, `?park_activity_id=`, `?park_id=`, `?reservation_id=`, `?date=YYYY-MM-DD`. Pagination 10/page.

Validation `POST` — **two payload shapes since DESD-95**:

**Timed-flow (existing)** — target a specific authored schedule:
```
reservation_id             required, exists:reservations
park_activity_schedule_id  required_without:park_activity_id, exists:park_activity_schedules
guests                     required, integer, min:1
```

**All-day flow (new — DESD-95)** — for `is_all_day=true` activities; the schedule is materialized lazily on the server:
```
reservation_id    required, exists:reservations
park_activity_id  required_without:park_activity_schedule_id, exists:park_activities
date              required_with:park_activity_id, date
guests            required, integer, min:1
```
The server locks the activity row, verifies `is_all_day=true`, then `firstOrCreate`-s a per-date `ParkActivitySchedule` whose `start_time`/`end_time` mirror `effectiveHoursOn(date)`. Subsequent bookings on the same `(activity, date)` reuse the materialized row. Closed / `not_configured` dates return `422 errors.date`. Passing `park_activity_id` for a non-all-day activity returns `422 errors.park_activity_id`.

Additional server-side business rules enforced in `ParkActivityBookingController::store`, each returning `422` with a specific validation key on failure:

- **Ownership** (`errors.reservation_id`): reservation must belong to the caller (superadmin / park-manager bypass).
- **Schedule bookable** (`errors.park_activity_schedule_id`): schedule `status === 'scheduled'` (not `cancelled` / `completed`) and `date >= today`.
- **Schedule fits effective hours** (`errors.park_activity_schedule_id`, DESD-95): defense-in-depth re-validation of the schedule's stored window against current effective hours, catches stale schedules from a bypassed cascade.
- **Park open** (`errors.park_activity_schedule_id`): `ThemePark::isOpenOn(date) === true`.
- **Seat pool > 0** (`errors.park_activity_schedule_id`): `Reservation::seatPoolOn(date) > 0`.
- **Seat pool cap** (`errors.guests`): `guests <= seatPoolOn(date)`.
- **Day-pass prerequisite** (`errors.reservation_id`): reservation must hold a confirmed `ParkBooking` for `(park_id, date)`.
- **Day-pass cap** (`errors.guests`): `guests <= day-pass.guests`.
- **Uniqueness** (`errors.park_activity_schedule_id`): no existing confirmed `ParkActivityBooking` for the same `(reservation_id, schedule_id)`.
- **Capacity** (`errors.park_activity_schedule_id`): `Σ confirmed guests on the schedule + guests <= activity.max_capacity`.

On create, `price_per_guest = activity.price`, `total_price = bcmul(activity.price, guests, 2)`. Rows default to `status="confirmed"`.

Validation `PATCH`:
```
status  sometimes, in:confirmed,cancelled
guests  sometimes, integer, min:1
```
If `guests` changes, seat-pool, day-pass, and capacity checks are re-run; `total_price` is recomputed. Schedule swap is not supported on PATCH — cancel and re-book.

`DELETE` — soft cancel (staff-only): sets `status=cancelled`, `cancelled_at=now()`, returns `204`. Customer call → `403`.

**Cancellation cascade** — same known gap shared by park and beach bookings: cancelling the underlying `RoomBooking` or `ParkBooking` does not auto-cancel attached activity bookings.

---

#### 15. Ferry Bookings

A ferry booking is a trip ticket for `N` guests on a specific `(slot, date)` pair. The slot is a `FerrySchedule` row (a fixed departure window on a vessel — see §7); the date lives on the booking row as `travel_date`. **No per-date schedule rows.**

**Booking shape.** A customer with a confirmed `RoomBooking` covering `travel_date` picks any `FerrySchedule` slot and any date inside `[check_in_date, check_out_date]` (inclusive on both ends — arrival-day and departure-day ferries are primary use cases).

**Inclusive reservation window.** Park, beach, and park-activity bookings use `Reservation::seatPoolOn` with **exclusive** checkout (on-island tickets aren't sold on the day guests are leaving). Ferries use the sibling `Reservation::ferrySeatPoolOn` with `check_in_date <= travel_date <= check_out_date` so arrival- and departure-day ferries are bookable.

**Park-closed-day rule (DESD-100).** Ferries don't run on dates the resort park is closed (any park returning `isOpenOn=false`). Booking attempts on a closed date return `422 errors.travel_date = ["Ferries are not available on this date because the park is closed."]`. Existing confirmed bookings on a date that subsequently closes via `ParkHourOverride` cascade-cancel — see §9 (hours-cascade).

**Auto-confirm on create.** If every rule passes (ownership, park-open, reservation covers travel date, no duplicate, capacity), `status=confirmed` immediately. If the vessel is full for that `(slot, date)`, the booking is rejected with `"There is no available space on this ferry."`.

**Cancellation is staff-only** — unified across every booking module (`ferry-manager` / `superadmin` only).

**No direction or port validation.** `departure_port` / `arrival_port` are free-text strings on the slot. The frontend renders them as authored.

**No round-trip coupling.** A reservation can hold any number of ferry bookings — e.g. outbound + return on the same day are two `FerryBooking` rows against two different slots.

`FerryBookingResource`:
```json
{
  "id": 77,
  "reservation_id": 7,
  "ferry_schedule_id": 22,
  "travel_date": "2026-05-02",
  "guests": 2,
  "status": "confirmed",       // confirmed | cancelled
  "price_per_guest": "40.00",
  "total_price": "80.00",
  "cancelled_at": null,
  "reservation": { /* ReservationResource when eager-loaded */ },
  "schedule":    { /* FerryScheduleResource when eager-loaded (includes `ferry` → `ferry_type`) */ },
  "created_at": "...",
  "updated_at": "..."
}
```

| Method | Path | Auth | Permission | Policy |
|---|---|---|---|---|
| GET    | `/ferry-bookings` | bearer | `bookings.view` | customer sees only their own; ferry-manager + superadmin see all |
| GET    | `/ferry-bookings/{ferry_booking}` | bearer | `bookings.view` | owner, ferry-manager, or superadmin |
| POST   | `/ferry-bookings` | bearer | `bookings.create` | superadmin, or customer attaching to their own reservation |
| PUT/PATCH | `/ferry-bookings/{ferry_booking}` | bearer | `bookings.update` | ferry-manager or superadmin |
| DELETE | `/ferry-bookings/{ferry_booking}` | bearer | `bookings.cancel` | ferry-manager or superadmin only (customers cannot cancel) |

Routes are per-verb — same reasoning as the other booking modules.

Index filters (all optional, AND-combined): `?status=confirmed|cancelled`, `?ferry_schedule_id=`, `?ferry_id=`, `?reservation_id=`, `?travel_date=YYYY-MM-DD`. Pagination 10/page.

Validation `POST`:
```
reservation_id     required, exists:reservations
ferry_schedule_id  required, exists:ferry_schedules
travel_date        required, date, after_or_equal:today
guests             required, integer, min:1
```

Business rules enforced in `FerryBookingController::store` (post-validation + inside `DB::transaction` with `lockForUpdate` on the slot row):

- **Ownership** (`errors.reservation_id`): reservation must belong to the caller (superadmin / ferry-manager bypass).
- **Park open** (`errors.travel_date`): every `ThemePark` returns `isOpenOn(travel_date) === true`. Closed via override → reject; `not_configured` → reject.
- **Reservation covers travel date** (`errors.travel_date`): `Reservation::ferrySeatPoolOn(travel_date) > 0` with inclusive window.
- **Seat pool cap** (`errors.guests`): `guests <= ferrySeatPoolOn(travel_date)`.
- **Uniqueness** (`errors.ferry_schedule_id`): no existing confirmed `FerryBooking` for the same `(reservation_id, ferry_schedule_id, travel_date)`. Same slot on a *different* date is fine; a same-day round trip on two different slots is fine. DB-level race-safe backstop: partial unique `ferry_booking_unique_confirmed (reservation_id, ferry_schedule_id, travel_date) WHERE status='confirmed'`.
- **Capacity** (`errors.ferry_schedule_id`): `Σ confirmed guests on (ferry_schedule_id, travel_date) + guests <= ferry.ferry_type.capacity`. Capacity lives on the type — vessels inherit it. Error message: `"There is no available space on this ferry."`. **Capacity is per-date** — a slot that's full on Monday is independently bookable on Tuesday.

On create, `price_per_guest = ferry.ferry_type.price`, `total_price = bcmul(price_per_guest, guests, 2)`. Rows default to `status="confirmed"`.

Validation `PATCH`:
```
status  sometimes, in:confirmed,cancelled
guests  sometimes, integer, min:1
```
If `guests` changes, seat-pool and capacity checks for the booking's existing `travel_date` are re-run inside the slot lock; `total_price` is recomputed. Slot swap and `travel_date` swap are **not** supported on PATCH — cancel and rebook to change either.

**Status transitions.** Only `confirmed → cancelled` (and no-ops) are accepted. **`cancelled → confirmed` is rejected with `422` on `status`** — re-confirming a cancelled row would need the full create-time invariant chain (park-open, per-reservation-per-slot-per-date uniqueness, seat pool, ferry capacity) to be re-run against the current world, and a customer asking to "undo" a cancellation should create a new booking instead. Client UX: on a cancelled row, hide/disable any "re-activate" affordance; surface a "book again" action that posts a fresh `POST /ferry-bookings`.

| From ↓ / To → | `confirmed` | `cancelled` |
|---|---|---|
| `confirmed` | no-op (200) | cancel (200, sets `cancelled_at`) |
| `cancelled` | **422 on `status`** | no-op (200) |

`DELETE` — soft cancel (staff-only): sets `status=cancelled`, `cancelled_at=now()`, returns `204`. Customer call → `403`. The partial unique excludes cancelled rows so the same `(reservation, slot, date)` can be rebooked after a cancel.

**Cascade entry points.** Three upstream events can cancel a confirmed ferry booking automatically:
1. **Slot archive** (`DELETE /ferry-schedules/{id}` with `on_conflict=cascade`) — soft-deletes the slot and cancels every confirmed booking on it across all dates. See §7.
2. **Ferry archive** (`DELETE /ferries/{ferry}` with `on_conflict=cascade`) — soft-deletes ferry + slots + cancels all confirmed bookings.
3. **Park-closed cascade** (`POST/PUT /theme-parks/{id}/hour-overrides` or opening-hour mutations with `on_conflict=cascade`) — cancels confirmed ferry bookings on dates the change closes. See §9.

Eager-loads on the booking response use `withTrashed()` for `schedule`, `schedule.ferry`, `schedule.ferry.ferryType` so a cancelled booking still serializes the (potentially archived) chain it ran against.

**Known gap.** Cancelling the underlying `RoomBooking` does not auto-cancel attached ferry bookings — same gap as the other booking modules.

---

#### 16. Suggested Next.js Client Layout

A minimal sketch — adapt to your routing conventions.

```
lib/api/
  client.ts          // fetch wrapper: base URL, Accept: application/json, bearer injection, 401 handler
  auth.ts            // login/register/logout/me
  hotels.ts          // list/get/create/update/delete + nested room-types & rooms
  ferries.ts         // ferries + ferry-schedules
  beach.ts           // beach activities + schedules
  users.ts
  types.ts           // Hotel, RoomType, Room, Ferry, FerrySchedule, BeachActivity, BeachActivitySchedule, User, Paginated<T>
```

`Paginated<T>` matches Laravel's default paginator:
```ts
type Paginated<T> = {
  data: T[];
  links: { first: string; last: string; prev: string | null; next: string | null };
  meta: { current_page: number; from: number; last_page: number; path: string; per_page: number; to: number; total: number };
};
```

Auth header injection example:
```ts
const res = await fetch(`${BASE}/api/hotels`, {
  headers: {
    Accept: "application/json",
    ...(token && { Authorization: `Bearer ${token}` }),
  },
});
```

Validation-error handling:
```ts
if (res.status === 422) {
  const { errors } = await res.json();
  // errors: Record<string, string[]>  → bind to form field-level messages
}
```

403 vs 401:
- **401** → token missing/expired → kick to login.
- **403** → token valid but action denied → show "you don't have permission" inline; do not redirect to login.

---

#### 17. Quick Reference: Endpoint Index

```
PUBLIC
GET    /api/hotels
GET    /api/hotels/{hotel}
GET    /api/hotels/{hotel}/room-types
GET    /api/hotels/{hotel}/room-types/{room_type}
GET    /api/hotels/{hotel}/rooms
GET    /api/hotels/{hotel}/rooms/{room}
GET    /api/hotels/{hotel}/availability                       [?from=&to= ; max 366-day range]
GET    /api/beach-activities
GET    /api/beach-activities/{beach_activity}
GET    /api/beach-activities/{beach_activity}/schedules
GET    /api/beach-activities/{beach_activity}/schedules/{schedule}
GET    /api/theme-parks
GET    /api/theme-parks/{theme_park}
GET    /api/theme-parks/{theme_park}/opening-hours
GET    /api/theme-parks/{theme_park}/opening-hours/{opening_hour}
GET    /api/theme-parks/{theme_park}/hour-overrides
GET    /api/theme-parks/{theme_park}/hour-overrides/{hour_override}
GET    /api/theme-parks/{theme_park}/effective-hours          [?date= | ?from=&to= ; max 366-day range]
GET    /api/theme-parks/{theme_park}/activities
GET    /api/theme-parks/{theme_park}/activities/{park_activity}
GET    /api/theme-parks/{theme_park}/activities/{park_activity}/schedules
GET    /api/theme-parks/{theme_park}/activities/{park_activity}/schedules/{schedule}
GET    /api/ferry-types
GET    /api/ferry-types/{ferry_type}
GET    /api/ferries
GET    /api/ferries/{ferry}
GET    /api/ferries/{ferry}/schedules
GET    /api/ferry-schedules
GET    /api/ferry-schedules/{ferry_schedule}
POST   /api/auth/register
POST   /api/auth/login

BEARER (auth:sanctum)
POST   /api/auth/logout
GET    /api/auth/me
GET    /api/auth/me/hotels                                    [superadmin: all live hotels; hotel-manager: assigned; others: []]

GET    /api/users                                             [superadmin — archived users hidden]
POST   /api/users                                             [superadmin]
GET    /api/users/{user}                                      [superadmin | self]
PUT    /api/users/{user}                                      [superadmin | self]
DELETE /api/users/{user}                                      [superadmin — archive; 409 if upcoming bookings]
POST   /api/users/{user}/restore                              [superadmin — un-archive]

POST   /api/hotels                                            [hotels.create → superadmin]
PUT    /api/hotels/{hotel}                                    [hotels.update → superadmin OR assigned hotel-manager]
DELETE /api/hotels/{hotel}                                    [hotels.delete → superadmin — archive; 409 if upcoming bookings]
POST   /api/hotels/{hotel}/restore                            [hotels.delete → superadmin — cascade-restores room-types + rooms]

POST   /api/hotels/{hotel}/room-types                         [room-types.create + manages hotel]
PUT    /api/hotels/{hotel}/room-types/{room_type}             [room-types.update + manages hotel]
DELETE /api/hotels/{hotel}/room-types/{room_type}             [room-types.delete + manages hotel — archive; 409 if upcoming bookings]
POST   /api/hotels/{hotel}/room-types/{room_type}/restore     [room-types.delete + manages hotel — 409 if parent hotel still archived]

POST   /api/hotels/{hotel}/rooms                              [rooms.create + manages hotel]
PUT    /api/hotels/{hotel}/rooms/{room}                       [rooms.update + manages hotel]
DELETE /api/hotels/{hotel}/rooms/{room}                       [rooms.delete + manages hotel — archive; 409 if upcoming bookings]
POST   /api/hotels/{hotel}/rooms/{room}/restore               [rooms.delete + manages hotel — 409 if parent hotel OR room-type still archived]

POST   /api/beach-activities                                  [beach.create]
PUT    /api/beach-activities/{beach_activity}                 [beach.update]
DELETE /api/beach-activities/{beach_activity}                 [superadmin]

POST   /api/beach-activities/{beach_activity}/schedules       [beach.create]
PUT    /api/beach-activities/{beach_activity}/schedules/{schedule}  [beach.update]
DELETE /api/beach-activities/{beach_activity}/schedules/{schedule}  [superadmin]

POST   /api/theme-parks                                       [park.create → park-manager or superadmin]
PUT    /api/theme-parks/{theme_park}                          [park.update → park-manager or superadmin]
DELETE /api/theme-parks/{theme_park}                          [park.delete → superadmin — archive; 409 if upcoming park or activity bookings]
POST   /api/theme-parks/{theme_park}/restore                  [park.delete → superadmin — cascade-restores activities + schedules]

POST   /api/theme-parks/{theme_park}/opening-hours            [park.create]
PUT    /api/theme-parks/{theme_park}/opening-hours/{opening_hour}  [park.update]
DELETE /api/theme-parks/{theme_park}/opening-hours/{opening_hour}  [park.delete]

POST   /api/theme-parks/{theme_park}/hour-overrides           [park.create — body: on_conflict=reject|cascade; cascade also cancels confirmed ferry bookings on closed dates]
PUT    /api/theme-parks/{theme_park}/hour-overrides/{hour_override}  [park.update — same cascade contract]
DELETE /api/theme-parks/{theme_park}/hour-overrides/{hour_override}  [park.delete — query: on_conflict=reject|cascade; same cascade contract]

POST   /api/theme-parks/{theme_park}/activities               [park.create]
PUT    /api/theme-parks/{theme_park}/activities/{park_activity}  [park.update]
DELETE /api/theme-parks/{theme_park}/activities/{park_activity}  [park.delete → superadmin — archive; 409 if upcoming activity bookings]
POST   /api/theme-parks/{theme_park}/activities/{park_activity}/restore  [park.delete → superadmin — 409 if parent park still archived]

POST   /api/theme-parks/{theme_park}/activities/{park_activity}/schedules       [park.create|park.update|park.delete]
PUT    /api/theme-parks/{theme_park}/activities/{park_activity}/schedules/{schedule}  [park.create|park.update|park.delete]
DELETE /api/theme-parks/{theme_park}/activities/{park_activity}/schedules/{schedule}  [park.create|park.update|park.delete — archive; 409 if upcoming activity bookings]
POST   /api/theme-parks/{theme_park}/activities/{park_activity}/schedules/{schedule}/restore  [park.create|park.update|park.delete — 409 if park OR activity still archived]

POST   /api/ferry-types                                       [ferry.create → ferry-manager or superadmin]
PUT    /api/ferry-types/{ferry_type}                          [ferry.update → ferry-manager or superadmin]
DELETE /api/ferry-types/{ferry_type}                          [superadmin — archive + cascade; body: on_conflict=reject|cascade]

POST   /api/ferries                                           [ferry.create → ferry-manager or superadmin]
PUT    /api/ferries/{ferry}                                   [ferry.update → ferry-manager or superadmin]
DELETE /api/ferries/{ferry}                                   [superadmin — archive + cascade; body: on_conflict=reject|cascade]

POST   /api/ferry-schedules                                   [ferry.create → slot CRUD: (ferry_id, departure_time, arrival_time, ports)]
PUT    /api/ferry-schedules/{ferry_schedule}                  [ferry.update → slot CRUD]
DELETE /api/ferry-schedules/{ferry_schedule}                  [ferry.delete → superadmin — archive + cascade; body: on_conflict=reject|cascade]

GET    /api/reservations                                      [bookings.view — customer: own; hotel-manager: reservations in managed hotels; superadmin: all]
GET    /api/reservations/{reservation}                        [bookings.view — same scope]

GET    /api/room-bookings                                     [bookings.view — customer: own; hotel-manager: managed hotels; superadmin: all. Filters: ?status ?hotel_id ?room_type_id ?reservation_id ?check_in_from ?check_in_to]
GET    /api/room-bookings/{room_booking}                      [bookings.view]
POST   /api/room-bookings                                     [bookings.create — auto-creates reservation if reservation_id omitted]
PUT    /api/room-bookings/{room_booking}                      [bookings.update — hotel-manager of that hotel or superadmin; customer cannot PATCH]
DELETE /api/room-bookings/{room_booking}                      [bookings.cancel — hotel-manager of the hotel, or superadmin — customer cannot DELETE]

GET    /api/park-bookings                                     [bookings.view — customer: own only; park-manager/superadmin: all]
GET    /api/park-bookings/{park_booking}                      [bookings.view]
POST   /api/park-bookings                                     [bookings.create — customer attaches to own reservation]
PUT    /api/park-bookings/{park_booking}                      [bookings.update — park-manager or superadmin]
DELETE /api/park-bookings/{park_booking}                      [bookings.cancel — park-manager or superadmin — customer cannot DELETE]

GET    /api/beach-bookings                                    [bookings.view — customer: own only; beach-manager/superadmin: all]
GET    /api/beach-bookings/{beach_booking}                    [bookings.view]
POST   /api/beach-bookings                                    [bookings.create — customer attaches to own reservation]
PUT    /api/beach-bookings/{beach_booking}                    [bookings.update — beach-manager or superadmin]
DELETE /api/beach-bookings/{beach_booking}                    [bookings.cancel — beach-manager or superadmin only]

GET    /api/park-activity-bookings                            [bookings.view — customer: own only; park-manager/superadmin: all]
GET    /api/park-activity-bookings/{park_activity_booking}    [bookings.view]
POST   /api/park-activity-bookings                            [bookings.create — requires prerequisite day-pass (ParkBooking)]
PUT    /api/park-activity-bookings/{park_activity_booking}    [bookings.update — park-manager or superadmin]
DELETE /api/park-activity-bookings/{park_activity_booking}    [bookings.cancel — park-manager or superadmin only]

GET    /api/ferry-bookings                                    [bookings.view — customer: own only; ferry-manager/superadmin: all]
GET    /api/ferry-bookings/{ferry_booking}                    [bookings.view]
POST   /api/ferry-bookings                                    [bookings.create — body: (reservation_id, ferry_schedule_id, travel_date, guests); inclusive window; rejected on park-closed dates]
PUT    /api/ferry-bookings/{ferry_booking}                    [bookings.update — ferry-manager or superadmin; status + guests only; reconfirm-from-cancelled blocked]
DELETE /api/ferry-bookings/{ferry_booking}                    [bookings.cancel — ferry-manager or superadmin only]
```
