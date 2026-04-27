<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

// Tests use UploadedFile::fake()->create(name, size, mime) rather than ->image(),
// because ->image() depends on the GD extension which isn't part of the project's
// required PHP extensions. The mime type is what the `image` and `mimes:` rules check.

test('unauthenticated user cannot upload', function () {
    $this->postJson('/api/uploads', [
        'file' => UploadedFile::fake()->create('photo.jpg', 100, 'image/jpeg'),
    ])->assertUnauthorized();
});

test('authenticated user can upload an image and gets back path + url', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson('/api/uploads', [
            'file' => UploadedFile::fake()->create('photo.jpg', 100, 'image/jpeg'),
            'folder' => 'hotels',
        ])
        ->assertCreated()
        ->assertJsonStructure(['path', 'url']);

    $path = $response->json('path');

    expect($path)->toStartWith('hotels/');
    Storage::disk('public')->assertExists($path);
    expect($response->json('url'))->toContain('/storage/'.$path);
});

test('upload defaults to misc folder when none provided', function () {
    $user = User::factory()->create();

    $path = $this->actingAs($user)
        ->postJson('/api/uploads', [
            'file' => UploadedFile::fake()->create('photo.jpg', 100, 'image/jpeg'),
        ])
        ->assertCreated()
        ->json('path');

    expect($path)->toStartWith('misc/');
});

test('upload rejects an unknown folder', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/uploads', [
            'file' => UploadedFile::fake()->create('photo.jpg', 100, 'image/jpeg'),
            'folder' => 'evil/../../etc',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['folder']);
});

test('upload rejects a non-image file', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/uploads', [
            'file' => UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf'),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file']);
});

test('upload rejects a file over the 10 MB cap', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/uploads', [
            'file' => UploadedFile::fake()->create('huge.jpg', 11000, 'image/jpeg'),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file']);
});

test('upload requires a file', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/uploads', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file']);
});
