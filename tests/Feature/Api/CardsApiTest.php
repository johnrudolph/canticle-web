<?php

use App\Models\Cards\Location;

test('GET /api/cards is public and returns all 4 card types with real booleans', function () {
    $response = $this->getJson('/api/cards');

    $response->assertOk()
        ->assertJsonStructure(['locations', 'relics', 'glyphs', 'golem_cards']);

    $locations = $response->json('locations');
    expect($locations)->not->toBeEmpty();
    expect($locations[0]['print'])->toBeBool();
});

test('GET /api/cards excludes soft-deleted cards', function () {
    $location = Location::create(['key' => 'to-be-deleted', 'name' => 'To Be Deleted']);
    $location->delete();

    $response = $this->getJson('/api/cards');

    $keys = collect($response->json('locations'))->pluck('key');
    expect($keys)->not->toContain('to-be-deleted');
});
