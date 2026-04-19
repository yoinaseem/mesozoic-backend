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
            'to' => ['nullable', 'date', 'required_with:from', 'after_or_equal:from'],
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

        $baseline = $themePark->openingHours()->get()->keyBy('day');
        $overrides = $themePark->hourOverrides()
            ->whereDate('date', '>=', $from->format('Y-m-d'))
            ->whereDate('date', '<=', $to->format('Y-m-d'))
            ->get()
            ->keyBy(fn ($o) => $o->date->format('Y-m-d'));

        $result = [];
        foreach (CarbonPeriod::create($from, $to) as $day) {
            $dateKey = $day->format('Y-m-d');
            $weekdayKey = strtolower($day->format('l'));
            $override = $overrides->get($dateKey);
            $base = $baseline->get($weekdayKey);

            $result[] = $this->resolveDay($dateKey, $override, $base);
        }

        return response()->json(['data' => $result]);
    }

    private function resolveDay(string $date, $override, $baseline): array
    {
        if ($override !== null) {
            $isClosed = $override->open_time === null && $override->close_time === null;

            return [
                'date' => $date,
                'status' => $isClosed ? 'closed' : 'open',
                'source' => 'override',
                'open_time' => $override->open_time,
                'close_time' => $override->close_time,
                'note' => $override->note,
            ];
        }

        if ($baseline !== null) {
            return [
                'date' => $date,
                'status' => 'open',
                'source' => 'baseline',
                'open_time' => $baseline->open_time,
                'close_time' => $baseline->close_time,
                'note' => null,
            ];
        }

        return [
            'date' => $date,
            'status' => 'not_configured',
            'source' => null,
            'open_time' => null,
            'close_time' => null,
            'note' => null,
        ];
    }
}
