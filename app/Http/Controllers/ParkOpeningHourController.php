<?php

namespace App\Http\Controllers;

use App\Exceptions\HoursCascadeConflictException;
use App\Http\Resources\ParkOpeningHourResource;
use App\Models\ParkOpeningHour;
use App\Models\ThemePark;
use App\Services\ParkScheduleReconciler;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ParkOpeningHourController extends Controller
{
    use AuthorizesRequests;

    /**
     * Window over which baseline-edit conflicts are checked. The
     * effective-hours endpoint validates `diffInDays + 1 <= 366`, which means
     * its furthest visible date is `today + 365`. Match that horizon exactly
     * so the cascade never touches schedules clients can't inspect.
     */
    private const CASCADE_RANGE_DAYS = 365;

    public function index(ThemePark $themePark): AnonymousResourceCollection
    {
        return ParkOpeningHourResource::collection(
            $themePark->openingHours()->paginate(10)
        );
    }

    public function show(ThemePark $themePark, ParkOpeningHour $openingHour): ParkOpeningHourResource
    {
        return new ParkOpeningHourResource($openingHour);
    }

    public function store(Request $request, ThemePark $themePark, ParkScheduleReconciler $reconciler): JsonResponse
    {
        $this->authorize('create', ParkOpeningHour::class);

        $data = $request->validate([
            'day' => [
                'required',
                Rule::in(ParkOpeningHour::DAYS),
                Rule::unique('park_opening_hours', 'day')->where('park_id', $themePark->id),
            ],
            'open_time' => ['required', 'date_format:H:i:s'],
            'close_time' => ['required', 'date_format:H:i:s', 'different:open_time'],
            'on_conflict' => ['sometimes', Rule::in(['reject', 'cascade'])],
        ]);

        $onConflict = $data['on_conflict'] ?? 'reject';
        unset($data['on_conflict']);

        try {
            [$openingHour, $cascadeResult] = DB::transaction(function () use ($themePark, $data, $reconciler, $onConflict) {
                $openingHour = $themePark->openingHours()->create($data);
                $conflicts = $this->findRangeConflicts($reconciler, $themePark);
                $ferryConflicts = $this->findRangeFerryConflicts($reconciler);
                $cascade = $this->applyOrThrow(
                    $reconciler,
                    $conflicts,
                    $ferryConflicts,
                    $onConflict,
                    "baseline added for {$data['day']}",
                );

                return [$openingHour, $cascade];
            });
        } catch (HoursCascadeConflictException $e) {
            return response()->json($e->payload(), 409);
        }

        return (new ParkOpeningHourResource($openingHour))
            ->additional(['cascade' => $cascadeResult])
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, ThemePark $themePark, ParkOpeningHour $openingHour, ParkScheduleReconciler $reconciler): JsonResponse
    {
        $this->authorize('update', $openingHour);

        $data = $request->validate([
            'day' => [
                'sometimes',
                Rule::in(ParkOpeningHour::DAYS),
                Rule::unique('park_opening_hours', 'day')
                    ->where('park_id', $themePark->id)
                    ->ignore($openingHour->id),
            ],
            'open_time' => ['sometimes', 'date_format:H:i:s'],
            'close_time' => ['sometimes', 'date_format:H:i:s'],
            'on_conflict' => ['sometimes', Rule::in(['reject', 'cascade'])],
        ]);

        $onConflict = $data['on_conflict'] ?? 'reject';
        unset($data['on_conflict']);

        $effectiveOpen = $data['open_time'] ?? $openingHour->open_time;
        $effectiveClose = $data['close_time'] ?? $openingHour->close_time;
        if ($effectiveOpen === $effectiveClose) {
            throw ValidationException::withMessages([
                'close_time' => 'The close time must be different from the open time.',
            ]);
        }

        try {
            [$openingHour, $cascadeResult] = DB::transaction(function () use ($themePark, $openingHour, $data, $reconciler, $onConflict) {
                $openingHour->update($data);
                $conflicts = $this->findRangeConflicts($reconciler, $themePark);
                $ferryConflicts = $this->findRangeFerryConflicts($reconciler);
                $cascade = $this->applyOrThrow(
                    $reconciler,
                    $conflicts,
                    $ferryConflicts,
                    $onConflict,
                    "baseline updated for {$openingHour->day}",
                );

                return [$openingHour, $cascade];
            });
        } catch (HoursCascadeConflictException $e) {
            return response()->json($e->payload(), 409);
        }

        return (new ParkOpeningHourResource($openingHour))
            ->additional(['cascade' => $cascadeResult])
            ->response();
    }

    public function destroy(Request $request, ThemePark $themePark, ParkOpeningHour $openingHour, ParkScheduleReconciler $reconciler): JsonResponse
    {
        $this->authorize('delete', $openingHour);

        $onConflict = $request->input('on_conflict', 'reject');
        if (! in_array($onConflict, ['reject', 'cascade'], true)) {
            throw ValidationException::withMessages([
                'on_conflict' => ['Invalid on_conflict mode.'],
            ]);
        }

        try {
            $cascadeResult = DB::transaction(function () use ($themePark, $openingHour, $reconciler, $onConflict) {
                $day = $openingHour->day;
                $openingHour->delete();
                $conflicts = $this->findRangeConflicts($reconciler, $themePark);
                $ferryConflicts = $this->findRangeFerryConflicts($reconciler);

                return $this->applyOrThrow(
                    $reconciler,
                    $conflicts,
                    $ferryConflicts,
                    $onConflict,
                    "baseline removed for {$day}",
                );
            });
        } catch (HoursCascadeConflictException $e) {
            return response()->json($e->payload(), 409);
        }

        if ($cascadeResult['schedules_cancelled'] === 0
            && $cascadeResult['bookings_cancelled'] === 0
            && $cascadeResult['ferry_bookings_cancelled'] === 0) {
            return response()->json(null, 204);
        }

        return response()->json(['cascade' => $cascadeResult]);
    }

    private function findRangeConflicts(ParkScheduleReconciler $reconciler, ThemePark $themePark): Collection
    {
        return $reconciler->findScheduleConflicts(
            $themePark,
            CarbonImmutable::parse(today()->toDateString()),
            CarbonImmutable::parse(today()->addDays(self::CASCADE_RANGE_DAYS)->toDateString()),
        );
    }

    private function findRangeFerryConflicts(ParkScheduleReconciler $reconciler): Collection
    {
        return $reconciler->findFerryBookingConflicts(
            CarbonImmutable::parse(today()->toDateString()),
            CarbonImmutable::parse(today()->addDays(self::CASCADE_RANGE_DAYS)->toDateString()),
        );
    }

    private function applyOrThrow(
        ParkScheduleReconciler $reconciler,
        Collection $conflicts,
        Collection $ferryConflicts,
        string $onConflict,
        string $reason,
    ): array {
        if ($conflicts->isEmpty() && $ferryConflicts->isEmpty()) {
            return [
                'schedules_cancelled' => 0,
                'bookings_cancelled' => 0,
                'ferry_bookings_cancelled' => 0,
            ];
        }

        if ($onConflict !== 'cascade') {
            throw new HoursCascadeConflictException($conflicts, $ferryConflicts);
        }

        $result = $reconciler->cascadeCancel($conflicts, $reason);
        $result['ferry_bookings_cancelled'] = $reconciler->cancelFerryBookings($ferryConflicts);

        return $result;
    }
}
