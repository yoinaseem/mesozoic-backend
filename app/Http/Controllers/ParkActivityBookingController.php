<?php

namespace App\Http\Controllers;

use App\Http\Resources\ParkActivityBookingResource;
use App\Models\ParkActivity;
use App\Models\ParkActivityBooking;
use App\Models\ParkActivitySchedule;
use App\Models\ParkBooking;
use App\Models\Reservation;
use App\Models\ThemePark;
use App\Services\ParkScheduleReconciler;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ParkActivityBookingController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ParkActivityBooking::class);
        $user = $request->user();

        $query = ParkActivityBooking::query()->with([
            'reservation.user'                => fn ($q) => $q->withTrashed(),
            'schedule'                        => fn ($q) => $q->withTrashed(),
            'schedule.parkActivity'           => fn ($q) => $q->withTrashed(),
            'schedule.parkActivity.themePark' => fn ($q) => $q->withTrashed(),
        ]);

        if ($user->hasRole('superadmin') || $user->hasRole('park-manager')) {
            // no scope
        } else {
            $query->whereHas('reservation', fn ($q) => $q->where('user_id', $user->id));
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($scheduleId = $request->query('park_activity_schedule_id')) {
            $query->where('park_activity_schedule_id', $scheduleId);
        }
        if ($reservationId = $request->query('reservation_id')) {
            $query->where('reservation_id', $reservationId);
        }
        if ($activityId = $request->query('park_activity_id')) {
            $query->whereHas('schedule', fn ($q) => $q->where('park_activity_id', $activityId));
        }
        if ($parkId = $request->query('park_id')) {
            $query->whereHas('schedule.parkActivity', fn ($q) => $q->where('park_id', $parkId));
        }
        if ($date = $request->query('date')) {
            $query->whereHas('schedule', fn ($q) => $q->whereDate('date', $date));
        }

        return ParkActivityBookingResource::collection(
            $query->latest('id')->paginate(10)
        );
    }

    public function show(ParkActivityBooking $parkActivityBooking): ParkActivityBookingResource
    {
        $this->authorize('view', $parkActivityBooking);

        $parkActivityBooking->load([
            'reservation.user'                => fn ($q) => $q->withTrashed(),
            'schedule'                        => fn ($q) => $q->withTrashed(),
            'schedule.parkActivity'           => fn ($q) => $q->withTrashed(),
            'schedule.parkActivity.themePark' => fn ($q) => $q->withTrashed(),
        ]);

        return new ParkActivityBookingResource($parkActivityBooking);
    }

    public function store(Request $request, ParkScheduleReconciler $reconciler): JsonResponse
    {
        $this->authorize('create', ParkActivityBooking::class);
        $user = $request->user();

        $data = $request->validate([
            'reservation_id' => ['required', Rule::exists('reservations', 'id')],
            'park_activity_schedule_id' => [
                'required_without:park_activity_id',
                'nullable',
                Rule::exists('park_activity_schedules', 'id'),
            ],
            'park_activity_id' => [
                'required_without:park_activity_schedule_id',
                'nullable',
                Rule::exists('park_activities', 'id'),
            ],
            'date' => ['required_with:park_activity_id', 'nullable', 'date'],
            'guests' => ['required', 'integer', 'min:1'],
        ]);

        $reservation = Reservation::findOrFail($data['reservation_id']);
        $this->assertReservationOwnedByCaller($user, $reservation);

        $booking = DB::transaction(function () use ($reservation, $data, $reconciler) {
            $schedule = $this->resolveSchedule($data, $reconciler);

            /** @var ParkActivity $activity */
            $activity = $schedule->parkActivity;
            /** @var ThemePark $park */
            $park = $activity->themePark;
            $scheduleDate = $schedule->date->toDateString();

            $this->assertScheduleBookable($schedule);
            $this->assertParkOpenOn($park, $scheduleDate);
            $this->assertScheduleStillFitsHours($schedule, $park, $reconciler);
            $this->assertReservationActiveOn($reservation, $scheduleDate, $data['guests']);
            $this->assertHoldsDayPass($reservation, $park, $scheduleDate, $data['guests']);
            $this->assertNoDuplicatePerSchedule($reservation->id, $schedule->id);
            $this->assertActivityCapacityAvailable($activity, $schedule, $data['guests']);

            $pricePerGuest = $activity->price;
            $totalPrice = bcmul((string) $pricePerGuest, (string) $data['guests'], 2);

            return ParkActivityBooking::create([
                'reservation_id' => $reservation->id,
                'park_activity_schedule_id' => $schedule->id,
                'guests' => $data['guests'],
                'status' => 'confirmed',
                'price_per_guest' => $pricePerGuest,
                'total_price' => $totalPrice,
            ]);
        });

        return (new ParkActivityBookingResource(
            $booking->load([
            'reservation.user'                => fn ($q) => $q->withTrashed(),
            'schedule'                        => fn ($q) => $q->withTrashed(),
            'schedule.parkActivity'           => fn ($q) => $q->withTrashed(),
            'schedule.parkActivity.themePark' => fn ($q) => $q->withTrashed(),
        ])
        ))->response()->setStatusCode(201);
    }

    public function update(Request $request, ParkActivityBooking $parkActivityBooking): ParkActivityBookingResource
    {
        $this->authorize('update', $parkActivityBooking);

        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['confirmed', 'cancelled'])],
            'guests' => ['sometimes', 'integer', 'min:1'],
        ]);

        if (($data['status'] ?? null) === 'cancelled' && $parkActivityBooking->status !== 'cancelled') {
            $data['cancelled_at'] = now();
        }

        if (array_key_exists('guests', $data)) {
            DB::transaction(function () use ($parkActivityBooking, &$data) {
                $schedule = ParkActivitySchedule::query()
                    ->whereKey($parkActivityBooking->park_activity_schedule_id)
                    ->with('parkActivity.themePark')
                    ->lockForUpdate()
                    ->firstOrFail();

                $activity = $schedule->parkActivity;
                $scheduleDate = $schedule->date->toDateString();

                $this->assertReservationActiveOn($parkActivityBooking->reservation, $scheduleDate, $data['guests']);
                $this->assertHoldsDayPass($parkActivityBooking->reservation, $activity->themePark, $scheduleDate, $data['guests']);
                $this->assertActivityCapacityAvailable($activity, $schedule, $data['guests'], $parkActivityBooking->id);

                $data['total_price'] = bcmul(
                    (string) $parkActivityBooking->price_per_guest,
                    (string) $data['guests'],
                    2,
                );

                $parkActivityBooking->update($data);
            });
        } else {
            $parkActivityBooking->update($data);
        }

        return new ParkActivityBookingResource(
            $parkActivityBooking->load([
            'reservation.user'                => fn ($q) => $q->withTrashed(),
            'schedule'                        => fn ($q) => $q->withTrashed(),
            'schedule.parkActivity'           => fn ($q) => $q->withTrashed(),
            'schedule.parkActivity.themePark' => fn ($q) => $q->withTrashed(),
        ])
        );
    }

    public function destroy(ParkActivityBooking $parkActivityBooking): JsonResponse
    {
        $this->authorize('delete', $parkActivityBooking);

        $parkActivityBooking->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        return response()->json(null, 204);
    }

    private function assertReservationOwnedByCaller(\App\Models\User $user, Reservation $reservation): void
    {
        if ($user->hasRole('superadmin') || $user->hasRole('park-manager')) {
            return;
        }

        if ($reservation->user_id !== $user->id) {
            throw ValidationException::withMessages([
                'reservation_id' => ['This reservation does not belong to you.'],
            ]);
        }
    }

    /**
     * Resolve the target schedule for booking. Two payload shapes:
     *   - park_activity_schedule_id: existing timed-flow path; lookup + lock.
     *   - park_activity_id + date: all-day flow; lock the activity, verify it
     *     is is_all_day, then materialize-or-fetch the per-date schedule via
     *     the reconciler.
     */
    private function resolveSchedule(array $data, ParkScheduleReconciler $reconciler): ParkActivitySchedule
    {
        if (! empty($data['park_activity_schedule_id'])) {
            return ParkActivitySchedule::query()
                ->whereKey($data['park_activity_schedule_id'])
                ->with('parkActivity.themePark')
                ->lockForUpdate()
                ->firstOrFail();
        }

        $activity = ParkActivity::query()
            ->whereKey($data['park_activity_id'])
            ->with('themePark')
            ->lockForUpdate()
            ->firstOrFail();

        if (! $activity->is_all_day) {
            throw ValidationException::withMessages([
                'park_activity_id' => [
                    'This activity is not all-day. Pass park_activity_schedule_id instead.',
                ],
            ]);
        }

        $schedule = $reconciler->materializeAllDaySchedule($activity, $data['date']);
        $schedule->setRelation('parkActivity', $activity);

        return $schedule;
    }

    /**
     * Only 'scheduled' slots are bookable. 'cancelled' and 'completed' rows
     * are rejected, and past-dated slots are too.
     */
    private function assertScheduleBookable(ParkActivitySchedule $schedule): void
    {
        if ($schedule->status !== ParkActivitySchedule::STATUS_SCHEDULED) {
            throw ValidationException::withMessages([
                'park_activity_schedule_id' => ['This schedule is not bookable.'],
            ]);
        }

        if ($schedule->date->toDateString() < now()->toDateString()) {
            throw ValidationException::withMessages([
                'park_activity_schedule_id' => ['This schedule is in the past.'],
            ]);
        }
    }

    private function assertParkOpenOn(ThemePark $park, string $date): void
    {
        if (! $park->isOpenOn($date)) {
            throw ValidationException::withMessages([
                'park_activity_schedule_id' => ['The park is not open on this date.'],
            ]);
        }
    }

    /**
     * Defense-in-depth: re-validate the schedule's stored window against
     * effective hours. Catches stale schedules that slipped through (e.g. a
     * cascade was bypassed by a force-unarchive path or hours changed without
     * the cascade running).
     */
    private function assertScheduleStillFitsHours(
        ParkActivitySchedule $schedule,
        ThemePark $park,
        ParkScheduleReconciler $reconciler,
    ): void {
        try {
            $reconciler->validateWindow(
                $park,
                $schedule->date->toDateString(),
                $schedule->start_time,
                $schedule->end_time,
            );
        } catch (ValidationException) {
            throw ValidationException::withMessages([
                'park_activity_schedule_id' => ["This schedule no longer fits the park's opening hours."],
            ]);
        }
    }

    /**
     * Same rule as ParkBookingController: reservation must have a confirmed
     * room booking covering the schedule date (exclusive checkout), and the
     * requested guest count must fit inside that seat pool.
     */
    private function assertReservationActiveOn(Reservation $reservation, string $date, int $guests): void
    {
        $pool = $reservation->seatPoolOn($date);

        if ($pool === 0) {
            throw ValidationException::withMessages([
                'park_activity_schedule_id' => ['The reservation has no confirmed rooms active on this date (check-out day is excluded).'],
            ]);
        }

        if ($guests > $pool) {
            throw ValidationException::withMessages([
                'guests' => ["Guests exceed the reservation's seat pool of {$pool} on this date."],
            ]);
        }
    }

    /**
     * Coupling rule: booking a park activity requires the reservation to
     * already hold a confirmed ParkBooking (day-pass) for the same park on
     * the same date, and the activity-booking guest count must fit inside
     * the day-pass guest count.
     */
    private function assertHoldsDayPass(Reservation $reservation, ThemePark $park, string $date, int $guests): void
    {
        $dayPass = ParkBooking::query()
            ->where('reservation_id', $reservation->id)
            ->where('park_id', $park->id)
            ->where('status', 'confirmed')
            ->whereDate('date', $date)
            ->first();

        if ($dayPass === null) {
            throw ValidationException::withMessages([
                'reservation_id' => ['This reservation has no confirmed park day-pass for this park on this date.'],
            ]);
        }

        if ($guests > (int) $dayPass->guests) {
            throw ValidationException::withMessages([
                'guests' => ["Guests exceed the day-pass count of {$dayPass->guests} held for this park on this date."],
            ]);
        }
    }

    /**
     * One booking per (reservation, schedule) among confirmed rows. Cancelled
     * rows don't block — cancel, then rebook is allowed.
     */
    private function assertNoDuplicatePerSchedule(int $reservationId, int $scheduleId): void
    {
        $exists = ParkActivityBooking::query()
            ->where('reservation_id', $reservationId)
            ->where('park_activity_schedule_id', $scheduleId)
            ->where('status', 'confirmed')
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'park_activity_schedule_id' => ['This reservation already has a booking for this schedule.'],
            ]);
        }
    }

    /**
     * Count-then-insert capacity check against ParkActivity.max_capacity.
     * Mirrors BeachBookingController::assertScheduleCapacityAvailable.
     */
    private function assertActivityCapacityAvailable(
        ParkActivity $activity,
        ParkActivitySchedule $schedule,
        int $incomingGuests,
        ?int $ignoreBookingId = null,
    ): void {
        $confirmedGuests = (int) ParkActivityBooking::query()
            ->where('park_activity_schedule_id', $schedule->id)
            ->where('status', 'confirmed')
            ->when($ignoreBookingId, fn ($q) => $q->where('id', '!=', $ignoreBookingId))
            ->sum('guests');

        if (($confirmedGuests + $incomingGuests) > $activity->max_capacity) {
            throw ValidationException::withMessages([
                'park_activity_schedule_id' => ['This schedule is at capacity.'],
            ]);
        }
    }
}
