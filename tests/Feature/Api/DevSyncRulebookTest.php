<?php

use App\Models\Cards\RuleBookRevision;

test('dev-sync rulebook-revisions endpoint returns unresolved revisions and the resolve endpoint clears them', function () {
    config(['debug_api.key' => 'test-key']);

    $revision = RuleBookRevision::create([
        'content' => 'Roads: move OUT of a Road space for free.',
        'justification' => 'Prevents chaining free moves across adjacent roads.',
    ]);
    $resolved = RuleBookRevision::create([
        'content' => 'Something already handled.',
        'justification' => 'n/a',
        'resolved_at' => now(),
    ]);

    $response = $this->withToken('test-key')->getJson('/api/dev-sync/rulebook-revisions');
    $response->assertOk();

    $ids = collect($response->json('revisions'))->pluck('id');
    expect($ids)->toContain($revision->id)
        ->and($ids)->not->toContain($resolved->id);

    $this->withToken('test-key')
        ->postJson("/api/dev-sync/rulebook-revisions/{$revision->id}/resolve", ['note' => 'Implemented in PR #42.'])
        ->assertOk();

    $revision->refresh();
    expect($revision->resolved_at)->not->toBeNull()
        ->and($revision->resolved_note)->toBe('Implemented in PR #42.');
});

test('dev-sync rulebook-revisions endpoint rejects requests without the debug API key', function () {
    $this->getJson('/api/dev-sync/rulebook-revisions')->assertUnauthorized();
});
