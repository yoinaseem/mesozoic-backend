### Mesozoic Backend — Frontend Integration Spec

This document describes every HTTP endpoint the Laravel API currently exposes, the auth model, response shapes, validation rules, and authorization rules. It is the contract for a Next.js client.

---

#### 1. Conventions

- **Base URL**: `${NEXT_PUBLIC_API_URL}/api` (e.g. `http://localhost:8000/api`).
- **Format**: JSON in, JSON out. Always send `Accept: application/json` so Laravel returns JSON for validation/auth errors instead of redirecting.
- **CSRF**: not required — this is a token-auth API (Sanctum personal access tokens, not the SPA cookie flow).
- **Auth header**: `Authorization: Bearer <token>` for any route inside the `auth:sanctum` group.
- **Pagination**: list endpoints return Laravel's standard paginator envelope (`data`, `links`, `meta`) at 15 items/page. Override page with `?page=2`.
- **Timestamps**: ISO-8601 strings (`2026-04-19T13:24:01.000000Z`).
- **Dates**: `YYYY-MM-DD` (e.g. `activity_date`, `travel_date`, `arrival_date`).
- **Times**: `HH:mm:ss` 24-hour (e.g. `09:30:00`). Validators reject anything else.
- **Money**: decimal strings cast at the model level; `BeachActivityResource` casts `price` to `float`. Treat as numeric, not string-equal.
- **Errors**:
  - `401 Unauthorized` — missing/invalid bearer token on a protected route.
  - `403 Forbidden` — token valid but the policy or `permission:` middleware denies the action.
  - `404 Not Found` — route-model binding failure (including nested-scope mismatches, e.g. `/hotels/1/rooms/9` where room 9 belongs to hotel 2).
  - `422 Unprocessable Entity` — validation. Body shape: `{ "message": "...", "errors": { "field": ["msg"] } }`.
  - `204 No Content` — successful delete.

---

#### 2. Auth & Session

| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST | `/auth/register` | public | Register a new customer. Auto-assigns the `customer` role. |
| POST | `/auth/login` | public | Exchange credentials for a Sanctum token. |
| POST | `/auth/logout` | bearer | Revoke the current token only (other devices keep working). |
| GET  | `/auth/me` | bearer | Return the authenticated user. |

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
Request: `{ "email": "...", "password": "..." }`. On bad creds returns `422` with `errors.email` (not 401).
Response `200`: same shape as register.

##### POST `/auth/logout`
No body. Response `200`: `{ "message": "Logged out successfully." }`. Only the token used in the request is revoked.

##### GET `/auth/me`
Response `200`: bare `UserResource` (no envelope).

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
- Anyone else: `view`/`update` allowed **only on their own record**; list/create/delete denied.

| Method | Path | Who can call |
|---|---|---|
| GET    | `/users`        | superadmin |
| GET    | `/users/{user}` | superadmin OR self |
| POST   | `/users`        | superadmin |
| PUT/PATCH | `/users/{user}` | superadmin OR self |
| DELETE | `/users/{user}` | superadmin |

Notes:
- `POST /users` does **not** auto-assign a role (unlike `/auth/register`). If you create users via this endpoint, role assignment is a separate concern not currently exposed.
- `PATCH /users/{me}` accepts partial `{ name?, email?, password? + password_confirmation }`. Self-update is intended for profile editing.

---

#### 4. Roles & Permissions Catalogue

Roles seeded by `RolesAndPermissionsSeeder`:

| Role | Module scope | Notes |
|---|---|---|
| `superadmin` | everything | `before()` short-circuits every policy. |
| `hotel-manager` | hotels they're assigned to (`hotel_user` pivot) | Can `hotels.update`, full CRUD on `room-types` and `rooms` of those hotels. Cannot create/delete hotels. |
| `ferry-manager` | all ferries | Has `ferry.view`, `ferry.create`, `ferry.update`. **No middleware enforces these on ferry routes today** — see §7. |
| `park-manager` | all park bookings | Has `bookings.view`, `bookings.update`, `bookings.cancel` — wired to §9 park-bookings routes. **Does not** have `bookings.create` (customers purchase their own day passes). |
| `beach-manager` | all beach activities | Has `beach.view`, `beach.create`, `beach.update`. Wired to routes. |
| `customer` | none | Default role on self-register. No permissions. |

Permission strings are `resource.action` (e.g. `hotels.update`, `beach.create`). The full seeded list lives in `database/seeders/RolesAndPermissionsSeeder.php`. Use `user.permissions` from `/auth/me` to drive UI; the server is authoritative.

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
| GET    | `/hotels`        | public | — | — |
| GET    | `/hotels/{hotel}` | public | — | — |
| POST   | `/hotels`        | bearer | `hotels.create` | superadmin only (`HotelPolicy::create` returns false for everyone else, so only `before()` lets it through). |
| PUT/PATCH | `/hotels/{hotel}` | bearer | `hotels.update` | superadmin OR (`hotels.update` AND assigned to that hotel via `hotel_user` pivot). |
| DELETE | `/hotels/{hotel}` | bearer | `hotels.delete` | superadmin only. |

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
| DELETE | `/hotels/{hotel}/room-types/{room_type}` | bearer | (any of the three) | `room-types.delete` AND user manages hotel. |

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
| DELETE | `/hotels/{hotel}/rooms/{room}` | bearer | (any) | `rooms.delete` AND manages hotel. |

Validation `POST`:
```
room_type_id required, must exist in room_types AND belong to {hotel}
room_no      string max:255 required, unique within {hotel}
```
`PATCH`: same with `sometimes`; uniqueness ignores the current room id.

---

#### 7. Ferries & Ferry Schedules

`Ferry` and `FerrySchedule` controllers return raw model JSON (not Resource classes) — keys come straight from the DB columns + Eloquent timestamps.

`Ferry` columns: `id, name, description, price (decimal:2), capacity, image, created_at, updated_at`. `GET /ferries/{ferry}` includes `schedules`.

`FerrySchedule` columns: `id, ferry_id, travel_date, departure_time, arrival_date, arrival_time, departure_port, arrival_port, status, created_at, updated_at`. `index`/`show`/`store`/`update` include the parent `ferry` object.

`status` enum: `scheduled` (default), `completed`, `cancelled`.

##### Ferries

| Method | Path | Auth | Authorization |
|---|---|---|---|
| GET    | `/ferries` | public | — |
| GET    | `/ferries/{ferry}` | public | includes `schedules` |
| POST   | `/ferries` | bearer | **only `auth:sanctum` — no permission middleware, no Ferry policy.** Any logged-in user can call it today. |
| PUT/PATCH | `/ferries/{ferry}` | bearer | same |
| DELETE | `/ferries/{ferry}` | bearer | same |

Treat ferry mutations as superadmin/ferry-manager only on the **client**; the server is currently permissive. Hide the UI for everyone else and don't rely on the API for enforcement until this is tightened.

Validation `POST`:
```
name        string max:255 required
description string nullable
price       numeric min:0 required
capacity    integer min:1 required
image       string max:255 nullable
```
`PATCH`: same with `sometimes`.

##### Ferry Schedules

| Method | Path | Auth |
|---|---|---|
| GET  | `/ferry-schedules` | public, paginated, includes `ferry` |
| GET  | `/ferry-schedules/{ferry_schedule}` | public, includes `ferry` |
| GET  | `/ferries/{ferry}/schedules` | public, schedules for one ferry, paginated |
| POST | `/ferry-schedules` | bearer (no further checks — same caveat as ferries) |
| PUT/PATCH | `/ferry-schedules/{ferry_schedule}` | bearer |
| DELETE | `/ferry-schedules/{ferry_schedule}` | bearer |

Validation `POST`:
```
ferry_id        required, exists:ferries
travel_date     required, date
departure_time  required, H:i:s, unique per (ferry_id, travel_date)
arrival_date    required, date, after_or_equal:travel_date
arrival_time    required, H:i:s
departure_port  required, string max:255
arrival_port    required, string max:255
status          optional, in:scheduled,completed,cancelled
```
Plus a controller-level check: arrival datetime (`arrival_date + arrival_time`) must be strictly after departure datetime (`travel_date + departure_time`). Violations come back as `422` with `errors.arrival_time = ["The arrival datetime must be after the departure datetime."]`.

`PATCH`: every field `sometimes`. Unique-departure check still applies, ignoring the current schedule id.

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
duration    integer min:1 required   // minutes
image       string max:255 nullable
```
`PATCH`: same with `sometimes`.

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
activity_date required, date
start_time    required, H:i:s, unique per (beach_activity_id, activity_date)
status        optional, in:pending,confirmed,cancelled  (default: pending)
```
`PATCH`: every field `sometimes`; unique-time check ignores the current schedule.

---

#### 9. Park Bookings

A park booking is a day-pass admission ticket tied to a `Reservation` (the trip envelope containing one or more room bookings). One booking covers `guests` people admitted to `park` on `date`.

The guest count is validated against `Reservation::seatPoolOn(date)` — the sum of confirmed room-booking guests active on that date with **exclusive checkout** (`check_in_date <= date < check_out_date`). This lets a single booking cover a group spread across multiple rooms (e.g. a family of 6 across 3 rooms buys one 6-guest day pass). It also means the check-out date always has a seat pool of 0 and is rejected.

Prerequisite: the caller's reservation must already have at least one confirmed `RoomBooking` covering `date`. The `Reservation`, `RoomBooking`, and `ThemePark` modules are not yet documented in this file — consult the Laravel controllers until a follow-up PR adds them.

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
| DELETE | `/park-bookings/{park_booking}` | bearer | `bookings.cancel` | owner (only before visit date), park-manager, or superadmin |

Routes are wired **per-verb** (not pipe-OR) because `customer` has `bookings.create|view|cancel` but not `bookings.update` — a pipe-OR would leak `PATCH` to customers at the middleware layer before the policy could deny it.

Index filters (all optional, AND-combined): `?status=confirmed|cancelled`, `?park_id=`, `?reservation_id=`, `?date=YYYY-MM-DD`. Pagination is Laravel default (15/page).

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

`DELETE` — soft cancel:
- Sets `status=cancelled` and `cancelled_at=now()`; returns `204` with no body. The row is preserved for audit.
- Customers can cancel only **before** the visit date (`now()->toDateString() < booking.date`). On or after the visit date, only park-manager / superadmin can cancel.

**Cancellation cascade (known gap).** Cancelling a `RoomBooking` that drops `seatPoolOn(date)` to 0 does **not** auto-cancel attached park bookings today. Treat this on the client as a potential stale-ticket risk until the cascade lands. Tracked as a follow-up against `RoomBookingController::enforceSeatPoolInvariantOrFail`.

**Multi-park note.** The database currently holds a hard limit of one `ThemePark`. The controller and uniqueness rule are already multi-park-ready — when more parks are added, the per-park uniqueness key supports splits (parents at Park A, kids at Park B on the same date) without code changes.

---

#### 10. Suggested Next.js Client Layout

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

#### 11. Quick Reference: Endpoint Index

```
PUBLIC
GET    /api/hotels
GET    /api/hotels/{hotel}
GET    /api/hotels/{hotel}/room-types
GET    /api/hotels/{hotel}/room-types/{room_type}
GET    /api/hotels/{hotel}/rooms
GET    /api/hotels/{hotel}/rooms/{room}
GET    /api/beach-activities
GET    /api/beach-activities/{beach_activity}
GET    /api/beach-activities/{beach_activity}/schedules
GET    /api/beach-activities/{beach_activity}/schedules/{schedule}
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

GET    /api/users                                             [superadmin]
POST   /api/users                                             [superadmin]
GET    /api/users/{user}                                      [superadmin | self]
PUT    /api/users/{user}                                      [superadmin | self]
DELETE /api/users/{user}                                      [superadmin]

POST   /api/hotels                                            [hotels.create → superadmin]
PUT    /api/hotels/{hotel}                                    [hotels.update → superadmin OR assigned hotel-manager]
DELETE /api/hotels/{hotel}                                    [hotels.delete → superadmin]

POST   /api/hotels/{hotel}/room-types                         [room-types.create + manages hotel]
PUT    /api/hotels/{hotel}/room-types/{room_type}             [room-types.update + manages hotel]
DELETE /api/hotels/{hotel}/room-types/{room_type}             [room-types.delete + manages hotel]

POST   /api/hotels/{hotel}/rooms                              [rooms.create + manages hotel]
PUT    /api/hotels/{hotel}/rooms/{room}                       [rooms.update + manages hotel]
DELETE /api/hotels/{hotel}/rooms/{room}                       [rooms.delete + manages hotel]

POST   /api/beach-activities                                  [beach.create]
PUT    /api/beach-activities/{beach_activity}                 [beach.update]
DELETE /api/beach-activities/{beach_activity}                 [superadmin]

POST   /api/beach-activities/{beach_activity}/schedules       [beach.create]
PUT    /api/beach-activities/{beach_activity}/schedules/{schedule}  [beach.update]
DELETE /api/beach-activities/{beach_activity}/schedules/{schedule}  [superadmin]

POST   /api/ferries                                           [auth-only — see §7]
PUT    /api/ferries/{ferry}                                   [auth-only]
DELETE /api/ferries/{ferry}                                   [auth-only]

POST   /api/ferry-schedules                                   [auth-only]
PUT    /api/ferry-schedules/{ferry_schedule}                  [auth-only]
DELETE /api/ferry-schedules/{ferry_schedule}                  [auth-only]

GET    /api/park-bookings                                     [bookings.view — customer: own only; park-manager/superadmin: all]
GET    /api/park-bookings/{park_booking}                      [bookings.view]
POST   /api/park-bookings                                     [bookings.create — customer attaches to own reservation]
PUT    /api/park-bookings/{park_booking}                      [bookings.update — park-manager or superadmin]
DELETE /api/park-bookings/{park_booking}                      [bookings.cancel — owner before visit date, or park-manager/superadmin]
```
