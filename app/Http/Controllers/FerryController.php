<?php

namespace App\Http\Controllers;

use App\Models\Ferry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FerryController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Ferry::paginate(15));
    }

    public function show(Ferry $ferry): JsonResponse
    {
        $ferry->load('schedules');

        return response()->json($ferry);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            'capacity' => ['required', 'integer', 'min:1'],
            'image' => ['nullable', 'string', 'max:255'],
        ]);

        $ferry = Ferry::create($data);

        return response()->json($ferry, 201);
    }

    public function update(Request $request, Ferry $ferry): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'capacity' => ['sometimes', 'integer', 'min:1'],
            'image' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $ferry->update($data);

        return response()->json($ferry);
    }

    public function destroy(Ferry $ferry): JsonResponse
    {
        $ferry->delete();

        return response()->json(null, 204);
    }
}