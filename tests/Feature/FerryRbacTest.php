<?php

use App\Models\Ferry;
use App\Models\FerryType;
use App\Models\User;

function frCustomer(): User
{
    $u = User::factory()->create();
    $u->assignRole('customer');

    return $u;
}

function frFerryManager(): User
{
    $u = User::factory()->create();
    $u->assignRole('ferry-manager');

    return $u;
}

function frSuperadmin(): User
{
    $u = User::factory()->create();
    $u->assignRole('superadmin');

    return $u;
}

test('public can list ferries without auth', function () {
    $type = FerryType::factory()->create();
    Ferry::factory()->create(['ferry_type_id' => $type->id]);

    $this->getJson('/api/ferries')->assertOk();
});

test('public can view a single ferry without auth', function () {
    $type = FerryType::factory()->create();
    $ferry = Ferry::factory()->create(['ferry_type_id' => $type->id]);

    $this->getJson("/api/ferries/{$ferry->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $ferry->id)
        ->assertJsonPath('data.ferry_type.id', $type->id);
});

test('unauthenticated user cannot create a ferry', function () {
    $type = FerryType::factory()->create();

    $this->postJson('/api/ferries', [
        'ferry_type_id' => $type->id,
        'name' => 'Vessel A',
    ])->assertUnauthorized();
});

test('customer cannot create a ferry', function () {
    $type = FerryType::factory()->create();

    $this->actingAs(frCustomer())
        ->postJson('/api/ferries', [
            'ferry_type_id' => $type->id,
            'name' => 'Vessel A',
        ])
        ->assertForbidden();
});

test('ferry-manager can create a ferry', function () {
    $type = FerryType::factory()->create();

    $this->actingAs(frFerryManager())
        ->postJson('/api/ferries', [
            'ferry_type_id' => $type->id,
            'name' => 'Vessel B',
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Vessel B')
        ->assertJsonPath('data.ferry_type_id', $type->id);
});

test('ferry-manager can update a ferry', function () {
    $type = FerryType::factory()->create();
    $ferry = Ferry::factory()->create(['ferry_type_id' => $type->id, 'name' => 'Old Name']);

    $this->actingAs(frFerryManager())
        ->patchJson("/api/ferries/{$ferry->id}", ['name' => 'New Name'])
        ->assertOk()
        ->assertJsonPath('data.name', 'New Name');
});

test('ferry-manager cannot delete a ferry', function () {
    $type = FerryType::factory()->create();
    $ferry = Ferry::factory()->create(['ferry_type_id' => $type->id]);

    $this->actingAs(frFerryManager())
        ->deleteJson("/api/ferries/{$ferry->id}")
        ->assertForbidden();
});

test('cannot create a ferry with a duplicate name under the same type', function () {
    $type = FerryType::factory()->create();
    Ferry::factory()->create(['ferry_type_id' => $type->id, 'name' => 'Voyager']);

    $this->actingAs(frFerryManager())
        ->postJson('/api/ferries', [
            'ferry_type_id' => $type->id,
            'name' => 'Voyager',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

test('can create a ferry with the same name under a different type', function () {
    $typeA = FerryType::factory()->create();
    $typeB = FerryType::factory()->create();
    Ferry::factory()->create(['ferry_type_id' => $typeA->id, 'name' => 'Voyager']);

    $this->actingAs(frFerryManager())
        ->postJson('/api/ferries', [
            'ferry_type_id' => $typeB->id,
            'name' => 'Voyager',
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Voyager')
        ->assertJsonPath('data.ferry_type_id', $typeB->id);
});

test('cannot rename a ferry to a name already used under the same type', function () {
    $type = FerryType::factory()->create();
    Ferry::factory()->create(['ferry_type_id' => $type->id, 'name' => 'Voyager']);
    $target = Ferry::factory()->create(['ferry_type_id' => $type->id, 'name' => 'Enterprise']);

    $this->actingAs(frFerryManager())
        ->patchJson("/api/ferries/{$target->id}", ['name' => 'Voyager'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

test('can keep the same name on update (ignore self)', function () {
    $type = FerryType::factory()->create();
    $ferry = Ferry::factory()->create(['ferry_type_id' => $type->id, 'name' => 'Voyager']);

    $this->actingAs(frFerryManager())
        ->patchJson("/api/ferries/{$ferry->id}", ['name' => 'Voyager'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Voyager');
});

test('superadmin can delete a ferry', function () {
    $type = FerryType::factory()->create();
    $ferry = Ferry::factory()->create(['ferry_type_id' => $type->id]);

    $this->actingAs(frSuperadmin())
        ->deleteJson("/api/ferries/{$ferry->id}")
        ->assertNoContent();
});
