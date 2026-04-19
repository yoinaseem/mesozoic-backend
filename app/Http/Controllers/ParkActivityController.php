<?php

namespace App\Http\Controllers;

use App\Http\Resources\ParkActivityResource;
use App\Models\ParkActivity;
use App\Models\ThemePark;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ParkActivityController extends Controller
{
    use AuthorizesRequests;

    public function index(ThemePark $themePark): AnonymousResourceCollection
    {
        return ParkActivityResource::collection(
            $themePark->parkActivities()->paginate(15)
        );
    }

    public function show(ThemePark $themePark, ParkActivity $parkActivity): ParkActivityResource
    {
        $parkActivity->load('schedules')->loadCount('schedules');

        return new ParkActivityResource($parkActivity);
    }

    public function store(Request $request, ThemePark $themePark): JsonResponse
    {
        $this->authorize('create', ParkActivity::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            'image' => ['nullable', 'string', 'max:255'],
            'duration' => [
                Rule::requiredIf(fn () => ! $request->boolean('is_all_day')),
                'nullable',
                'integer',
                'min:1',
            ],
            'max_capacity' => ['required', 'integer', 'min:1'],
            'is_all_day' => ['sometimes', 'boolean'],
        ]);

        if ($request->boolean('is_all_day')) {
            $data['duration'] = null;
        }

        $parkActivity = $themePark->parkActivities()->create($data);

        return (new ParkActivityResource($parkActivity))->response()->setStatusCode(201);
    }

    public function update(Request $request, ThemePark $themePark, ParkActivity $parkActivity): ParkActivityResource
    {
        $this->authorize('update', $parkActivity);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'image' => ['sometimes', 'nullable', 'string', 'max:255'],
            'duration' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'max_capacity' => ['sometimes', 'integer', 'min:1'],
            'is_all_day' => ['sometimes', 'boolean'],
        ]);

        $effectiveIsAllDay = array_key_exists('is_all_day', $data)
            ? (bool) $data['is_all_day']
            : (bool) $parkActivity->is_all_day;

        if ($effectiveIsAllDay) {
            $data['duration'] = null;
        } else {
            $effectiveDuration = array_key_exists('duration', $data)
                ? $data['duration']
                : $parkActivity->duration;
            if ($effectiveDuration === null) {
                throw ValidationException::withMessages([
                    'duration' => 'The duration is required when the activity is not all-day.',
                ]);
            }
        }

        $parkActivity->update($data);

        return new ParkActivityResource($parkActivity);
    }

    public function destroy(ThemePark $themePark, ParkActivity $parkActivity): JsonResponse
    {
        $this->authorize('delete', $parkActivity);

        $parkActivity->delete();

        return response()->json(null, 204);
    }
}
