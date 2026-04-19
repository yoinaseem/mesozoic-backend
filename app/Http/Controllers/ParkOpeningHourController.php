<?php

namespace App\Http\Controllers;

use App\Http\Resources\ParkOpeningHourResource;
use App\Models\ParkOpeningHour;
use App\Models\ThemePark;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ParkOpeningHourController extends Controller
{
    use AuthorizesRequests;

    public function index(ThemePark $themePark): AnonymousResourceCollection
    {
        return ParkOpeningHourResource::collection(
            $themePark->openingHours()->paginate(15)
        );
    }

    public function show(ThemePark $themePark, ParkOpeningHour $openingHour): ParkOpeningHourResource
    {
        return new ParkOpeningHourResource($openingHour);
    }

    public function store(Request $request, ThemePark $themePark): JsonResponse
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
        ]);

        $openingHour = $themePark->openingHours()->create($data);

        return (new ParkOpeningHourResource($openingHour))->response()->setStatusCode(201);
    }

    public function update(Request $request, ThemePark $themePark, ParkOpeningHour $openingHour): ParkOpeningHourResource
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
        ]);

        $effectiveOpen = $data['open_time'] ?? $openingHour->open_time;
        $effectiveClose = $data['close_time'] ?? $openingHour->close_time;
        if ($effectiveOpen === $effectiveClose) {
            throw ValidationException::withMessages([
                'close_time' => 'The close time must be different from the open time.',
            ]);
        }

        $openingHour->update($data);

        return new ParkOpeningHourResource($openingHour);
    }

    public function destroy(ThemePark $themePark, ParkOpeningHour $openingHour): JsonResponse
    {
        $this->authorize('delete', $openingHour);

        $openingHour->delete();

        return response()->json(null, 204);
    }
}
