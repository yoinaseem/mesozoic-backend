<?php

namespace App\Http\Controllers;

use App\Exceptions\HoursCascadeConflictException;
use App\Http\Resources\ParkHourOverrideResource;
use App\Models\ParkHourOverride;
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

class ParkHourOverrideController extends Controller
{
    use AuthorizesRequests;

    public function index(ThemePark $themePark): AnonymousResourceCollection
    {
        return ParkHourOverrideResource::collection(
            $themePark->hourOverrides()->orderBy('date')->paginate(10)
        );
    }

    public function show(ThemePark $themePark, ParkHourOverride $hourOverride): ParkHourOverrideResource
    {
        return new ParkHourOverrideResource($hourOverride);
    }

    public function store(Request $request, ThemePark $themePark, ParkScheduleReconciler $reconciler): JsonResponse
    {
        $this->authorize('create', ParkHourOverride::class);

        $data = $request->validate([
            'date' => [
                'required',
                'date',
                function ($attribute, $value, $fail) use ($themePark) {
                    if ($themePark->hourOverrides()->whereDate('date', $value)->exists()) {
                        $fail('An override already exists for this date.');
                    }
                },
            ],
            'open_time' => ['nullable', 'required_with:close_time', 'date_format:H:i:s'],
            'close_time' => ['nullable', 'required_with:open_time', 'date_format:H:i:s', 'different:open_time'],
            'note' => ['nullable', 'string', 'max:255'],
            'on_conflict' => ['sometimes', Rule::in(['reject', 'cascade'])],
        ]);

        $onConflict = $data['on_conflict'] ?? 'reject';
        unset($data['on_conflict']);

        try {
            [$override, $cascadeResult] = DB::transaction(function () use ($themePark, $data, $reconciler, $onConflict) {
                $override = $themePark->hourOverrides()->create($data);

                $conflicts = $reconciler->findScheduleConflicts(
                    $themePark,
                    CarbonImmutable::parse($data['date']),
                    CarbonImmutable::parse($data['date']),
                );

                $cascade = $this->applyOrThrow(
                    $reconciler,
                    $conflicts,
                    $onConflict,
                    "override applied for {$data['date']}",
                );

                return [$override, $cascade];
            });
        } catch (HoursCascadeConflictException $e) {
            return response()->json($e->payload(), 409);
        }

        return (new ParkHourOverrideResource($override))
            ->additional(['cascade' => $cascadeResult])
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, ThemePark $themePark, ParkHourOverride $hourOverride, ParkScheduleReconciler $reconciler): JsonResponse
    {
        $this->authorize('update', $hourOverride);

        $data = $request->validate([
            'date' => [
                'sometimes',
                'date',
                function ($attribute, $value, $fail) use ($themePark, $hourOverride) {
                    $exists = $themePark->hourOverrides()
                        ->whereDate('date', $value)
                        ->where('id', '!=', $hourOverride->id)
                        ->exists();
                    if ($exists) {
                        $fail('An override already exists for this date.');
                    }
                },
            ],
            'open_time' => ['sometimes', 'nullable', 'date_format:H:i:s'],
            'close_time' => ['sometimes', 'nullable', 'date_format:H:i:s'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
            'on_conflict' => ['sometimes', Rule::in(['reject', 'cascade'])],
        ]);

        $onConflict = $data['on_conflict'] ?? 'reject';
        unset($data['on_conflict']);

        $effectiveOpen = array_key_exists('open_time', $data) ? $data['open_time'] : $hourOverride->open_time;
        $effectiveClose = array_key_exists('close_time', $data) ? $data['close_time'] : $hourOverride->close_time;

        $openSet = $effectiveOpen !== null;
        $closeSet = $effectiveClose !== null;
        if ($openSet !== $closeSet) {
            throw ValidationException::withMessages([
                'open_time' => 'Open time and close time must both be set, or both be null (closed).',
            ]);
        }
        if ($openSet && $closeSet && $effectiveOpen === $effectiveClose) {
            throw ValidationException::withMessages([
                'close_time' => 'The close time must be different from the open time.',
            ]);
        }

        $oldDate = $hourOverride->date->toDateString();

        try {
            [$override, $cascadeResult] = DB::transaction(function () use ($themePark, $hourOverride, $data, $reconciler, $onConflict, $oldDate) {
                $hourOverride->update($data);
                $newDate = $hourOverride->fresh()->date->toDateString();

                $conflicts = collect();
                foreach (collect([$oldDate, $newDate])->unique()->values() as $d) {
                    $conflicts = $conflicts->concat($reconciler->findScheduleConflicts(
                        $themePark,
                        CarbonImmutable::parse($d),
                        CarbonImmutable::parse($d),
                    ));
                }

                $cascade = $this->applyOrThrow(
                    $reconciler,
                    $conflicts,
                    $onConflict,
                    "override updated ({$oldDate} → {$newDate})",
                );

                return [$hourOverride, $cascade];
            });
        } catch (HoursCascadeConflictException $e) {
            return response()->json($e->payload(), 409);
        }

        return (new ParkHourOverrideResource($override))
            ->additional(['cascade' => $cascadeResult])
            ->response();
    }

    public function destroy(Request $request, ThemePark $themePark, ParkHourOverride $hourOverride, ParkScheduleReconciler $reconciler): JsonResponse
    {
        $this->authorize('delete', $hourOverride);

        // Deleting an override usually widens hours back to baseline, but two
        // cases can narrow them and invalidate live schedules:
        //   - the override was broader than baseline (e.g. event-day extended
        //     hours), so deletion shrinks the open window
        //   - no baseline is configured, so deletion makes the day not_configured
        // Run the same conflict-scan / cascade flow as store/update.
        $onConflict = $request->input('on_conflict', 'reject');
        if (! in_array($onConflict, ['reject', 'cascade'], true)) {
            throw ValidationException::withMessages([
                'on_conflict' => ['Invalid on_conflict mode.'],
            ]);
        }

        $date = $hourOverride->date->toDateString();

        try {
            $cascadeResult = DB::transaction(function () use ($themePark, $hourOverride, $reconciler, $onConflict, $date) {
                $hourOverride->delete();

                $conflicts = $reconciler->findScheduleConflicts(
                    $themePark,
                    CarbonImmutable::parse($date),
                    CarbonImmutable::parse($date),
                );

                return $this->applyOrThrow(
                    $reconciler,
                    $conflicts,
                    $onConflict,
                    "override removed for {$date}",
                );
            });
        } catch (HoursCascadeConflictException $e) {
            return response()->json($e->payload(), 409);
        }

        if ($cascadeResult['schedules_cancelled'] === 0 && $cascadeResult['bookings_cancelled'] === 0) {
            return response()->json(null, 204);
        }

        return response()->json(['cascade' => $cascadeResult]);
    }

    /**
     * Either rollback (no-cascade mode) or cascade-cancel + return summary.
     * Common to store/update; called inside the transaction.
     */
    private function applyOrThrow(
        ParkScheduleReconciler $reconciler,
        Collection $conflicts,
        string $onConflict,
        string $reason,
    ): array {
        if ($conflicts->isEmpty()) {
            return ['schedules_cancelled' => 0, 'bookings_cancelled' => 0];
        }

        if ($onConflict !== 'cascade') {
            throw new HoursCascadeConflictException($conflicts);
        }

        return $reconciler->cascadeCancel($conflicts, $reason);
    }
}
