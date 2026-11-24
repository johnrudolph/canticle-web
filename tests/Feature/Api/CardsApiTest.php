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

test('GET /api/cards?type=relic returns a flat array, not the nested shape', function () {
    $response = $this->getJson('/api/cards?type=relic');

    $response->assertOk();
    $body = $response->json();
    expect($body)->toBeArray()
        ->and(array_is_list($body))->toBeTrue()
        ->and($body)->not->toBeEmpty();
    // Relic's own `type` column (Action/Passive/Event) survives
    // untouched — no card-category tag was mixed into the same key.
    expect($body[0])->toHaveKey('type')
        ->and($body[0]['type'])->toBeIn(['Action', 'Passive', 'Event']);
});

test('GET /api/cards?type= accepts every card type, singular or plural', function () {
    foreach (['location', 'locations', 'glyph', 'glyphs', 'golem_card', 'golem_cards'] as $type) {
        $response = $this->getJson("/api/cards?type={$type}");
        $response->assertOk();
        expect($response->json())->not->toBeEmpty();
    }
});

test('GET /api/cards?type=bogus returns an empty array, not an error', function () {
    $response = $this->getJson('/api/cards?type=bogus');

    $response->assertOk()->assertExactJson([]);
});
