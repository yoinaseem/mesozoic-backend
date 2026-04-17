<?php

namespace App\Http\Controllers;

use App\Http\Resources\ThemeParkResource;
use App\Models\ThemePark;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ThemeParkController extends Controller
{
    use AuthorizesRequests;

    public function index(): AnonymousResourceCollection
    {
        return ThemeParkResource::collection(ThemePark::paginate(15));
    }

    public function show(ThemePark $themePark): ThemeParkResource
    {
        $themePark->load([
            'openingHours',
            'activities' => fn ($q) => $q->withCount('schedules'),
        ]);

        return new ThemeParkResource($themePark);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', ThemePark::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'images' => ['nullable', 'array'],
            'images.*' => ['string', 'max:2048'],
            'description' => ['required', 'string'],
            'capacity' => ['required', 'integer', 'min:1'],
            'price' => ['required', 'numeric', 'min:0'],
            'contact_email' => ['required', 'email', 'max:255'],
            'contact_phone' => ['required', 'string', 'max:50'],
        ]);

        $themePark = ThemePark::create($data);

        return (new ThemeParkResource($themePark))->response()->setStatusCode(201);
    }

    public function update(Request $request, ThemePark $themePark): ThemeParkResource
    {
        $this->authorize('update', $themePark);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'images' => ['sometimes', 'nullable', 'array'],
            'images.*' => ['string', 'max:2048'],
            'description' => ['sometimes', 'string'],
            'capacity' => ['sometimes', 'integer', 'min:1'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'contact_email' => ['sometimes', 'email', 'max:255'],
            'contact_phone' => ['sometimes', 'string', 'max:50'],
        ]);

        $themePark->update($data);

        return new ThemeParkResource($themePark);
    }

    public function destroy(ThemePark $themePark): JsonResponse
    {
        $this->authorize('delete', $themePark);

        $themePark->delete();

        return response()->json(null, 204);
    }
}
