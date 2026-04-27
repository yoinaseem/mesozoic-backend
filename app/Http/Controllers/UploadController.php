<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class UploadController extends Controller
{
    private const ALLOWED_FOLDERS = [
        'hotels',
        'room-types',
        'park-activities',
        'beach-activities',
        'ferry-types',
        'theme-parks',
        'misc',
    ];

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'image', 'mimes:jpeg,png,webp,gif', 'max:10240'],
            'folder' => ['nullable', 'string', Rule::in(self::ALLOWED_FOLDERS)],
        ]);

        $folder = $data['folder'] ?? 'misc';
        $path = $request->file('file')->store($folder, 'public');

        return response()->json([
            'path' => $path,
            'url' => Storage::disk('public')->url($path),
        ], 201);
    }
}
