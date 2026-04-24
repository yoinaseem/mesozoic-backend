<?php

namespace App\Http\Controllers;

use App\Http\Resources\ParkHourOverrideResource;
use App\Models\ParkHourOverride;
use App\Models\ThemePark;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
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

    public function store(Request $request, ThemePark $themePark): JsonResponse
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
        ]);

        $override = $themePark->hourOverrides()->create($data);

        return (new ParkHourOverrideResource($override))->response()->setStatusCode(201);
    }

    public function update(Request $request, ThemePark $themePark, ParkHourOverride $hourOverride): ParkHourOverrideResource
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
        ]);

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

        $hourOverride->update($data);

        return new ParkHourOverrideResource($hourOverride);
    }

    public function destroy(ThemePark $themePark, ParkHourOverride $hourOverride): JsonResponse
    {
        $this->authorize('delete', $hourOverride);

        $hourOverride->delete();

        return response()->json(null, 204);
    }
}
