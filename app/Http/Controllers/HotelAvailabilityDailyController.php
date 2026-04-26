<?php

namespace App\Http\Controllers;

use App\Models\Hotel;
use App\Services\RoomAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class HotelAvailabilityDailyController extends Controller
{
    private const MAX_RANGE_DAYS = 366;

    public function __invoke(Request $request, Hotel $hotel, RoomAvailability $service): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to'   => ['nullable', 'date'],
        ]);

        $from = isset($data['from'])
            ? CarbonImmutable::parse($data['from'])->startOfDay()
            : CarbonImmutable::today();

        $to = isset($data['to'])
            ? CarbonImmutable::parse($data['to'])->startOfDay()
            : $from->addDay();

        if ($to->lessThanOrEqualTo($from)) {
            throw ValidationException::withMessages([
                'to' => 'The to date must be after the from date.',
            ]);
        }

        if ($from->diffInDays($to) > self::MAX_RANGE_DAYS) {
            throw ValidationException::withMessages([
                'to' => 'Range cannot exceed '.self::MAX_RANGE_DAYS.' days.',
            ]);
        }

        $roomTypes = $service->forHotelDaily(
            $hotel,
            $from->format('Y-m-d'),
            $to->format('Y-m-d'),
        );

        return response()->json([
            'data' => [
                'hotel_id'   => $hotel->id,
                'from'       => $from->format('Y-m-d'),
                'to'         => $to->format('Y-m-d'),
                'room_types' => $roomTypes,
            ],
        ]);
    }
}
