<?php

namespace App\Http\Controllers;

use App\Models\ThemePark;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ParkEffectiveHoursController extends Controller
{
    private const MAX_RANGE_DAYS = 366;

    public function __invoke(Request $request, ThemePark $themePark): JsonResponse
    {
        $data = $request->validate([
            'date' => ['nullable', 'date'],
            'from' => ['nullable', 'date', 'required_with:to'],
            'to'   => ['nullable', 'date', 'required_with:from', 'after_or_equal:from'],
        ]);

        if (empty($data['date']) && empty($data['from'])) {
            throw ValidationException::withMessages([
                'date' => 'Provide either a date or a from/to range.',
            ]);
        }

        if (! empty($data['date'])) {
            $from = $to = CarbonImmutable::parse($data['date'])->startOfDay();
        } else {
            $from = CarbonImmutable::parse($data['from'])->startOfDay();
            $to = CarbonImmutable::parse($data['to'])->startOfDay();
        }

        if ($from->diffInDays($to) + 1 > self::MAX_RANGE_DAYS) {
            throw ValidationException::withMessages([
                'to' => 'Range cannot exceed '.self::MAX_RANGE_DAYS.' days.',
            ]);
        }

        $result = [];
        foreach (CarbonPeriod::create($from, $to) as $day) {
            $result[] = $themePark->effectiveHoursOn($day);
        }

        return response()->json(['data' => $result]);
    }
}
