<?php

namespace App\Http\Controllers;

use App\Http\Resources\ParkOpeningHourResource;
use App\Models\ParkOpeningHour;
use App\Models\ThemePark;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

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
                'string',
                'max:50',
                Rule::unique('park_opening_hours', 'day')->where('park_id', $themePark->id),
            ],
            'open_time' => ['required', 'date_format:H:i:s'],
            'close_time' => ['required', 'date_format:H:i:s', 'after:open_time'],
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
                'string',
                'max:50',
                Rule::unique('park_opening_hours', 'day')
                    ->where('park_id', $themePark->id)
                    ->ignore($openingHour->id),
            ],
            'open_time' => ['sometimes', 'date_format:H:i:s'],
            'close_time' => ['sometimes', 'date_format:H:i:s'],
        ]);

        $open = $data['open_time'] ?? $openingHour->open_time;
        $close = $data['close_time'] ?? $openingHour->close_time;
        Validator::make(
            ['open_time' => $open, 'close_time' => $close],
            ['close_time' => ['required', 'date_format:H:i:s', 'after:open_time']],
            [],
            ['open_time' => 'open time', 'close_time' => 'close time']
        )->validate();

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
