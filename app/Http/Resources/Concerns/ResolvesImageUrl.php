<?php

namespace App\Http\Resources\Concerns;

use Illuminate\Support\Facades\Storage;

trait ResolvesImageUrl
{
    protected function resolveImageUrl(?string $image): ?string
    {
        if ($image === null || $image === '') {
            return null;
        }

        return str_starts_with($image, 'http://') || str_starts_with($image, 'https://')
            ? $image
            : Storage::disk('public')->url($image);
    }

    protected function resolveImageUrls(mixed $images): ?array
    {
        if (! is_array($images) || $images === []) {
            return null;
        }

        return array_values(array_filter(
            array_map(fn ($img) => is_string($img) ? $this->resolveImageUrl($img) : null, $images),
            fn ($url) => $url !== null,
        ));
    }
}
