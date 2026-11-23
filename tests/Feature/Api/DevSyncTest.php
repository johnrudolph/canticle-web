<?php

use App\Models\Cards\CardComment;
use App\Models\Cards\Relic;

test('dev-sync endpoints reject requests without the debug API key', function () {
    $relic = Relic::create(['key' => 'unauthed-test-relic', 'name' => 'Unauthed Test Relic']);
    $comment = CardComment::create([
        'commentable_type' => 'relic',
        'commentable_id' => $relic->id,
        'kind' => 'comment',
        'body' => 'n/a',
    ]);

    $this->getJson('/api/dev-sync/comments')->assertUnauthorized();
    $this->postJson("/api/dev-sync/comments/{$comment->id}/resolve")->assertUnauthorized();
});

test('dev-sync comments endpoint returns unresolved comments by default, with the card inlined', function () {
    config(['debug_api.key' => 'test-key']);
    $relic = Relic::create(['key' => 'flagged-relic', 'name' => 'Flagged Relic']);
    $comment = CardComment::create([
        'commentable_type' => 'relic',
        'commentable_id' => $relic->id,
        'kind' => 'comment',
        'body' => 'This needs fixing.',
    ]);
    $resolved = CardComment::create([
        'commentable_type' => 'relic',
        'commentable_id' => $relic->id,
        'kind' => 'comment',
        'body' => 'Already handled.',
        'resolved_at' => now(),
    ]);

    $response = $this->withToken('test-key')->getJson('/api/dev-sync/comments');

    $response->assertOk();
    $ids = collect($response->json('comments'))->pluck('id');

    expect($ids)->toContain($comment->id)
        ->and($ids)->not->toContain($resolved->id);

    $returned = collect($response->json('comments'))->firstWhere('id', $comment->id);
    expect($returned['card']['key'])->toBe('flagged-relic')
        ->and($returned['card']['name'])->toBe('Flagged Relic');
});

test('dev-sync resolve endpoint marks a comment resolved', function () {
    config(['debug_api.key' => 'test-key']);
    $relic = Relic::create(['key' => 'another-relic', 'name' => 'Another Relic']);
    $comment = CardComment::create([
        'commentable_type' => 'relic',
        'commentable_id' => $relic->id,
        'kind' => 'deletion',
        'body' => 'Removed, clean up references.',
    ]);

    $this->withToken('test-key')
        ->postJson("/api/dev-sync/comments/{$comment->id}/resolve", ['note' => 'Cleaned up.'])
        ->assertOk();

    $comment->refresh();
    expect($comment->resolved_at)->not->toBeNull()
        ->and($comment->resolved_note)->toBe('Cleaned up.');
});

test('dev-sync comments endpoint finds the card even if it has since been soft-deleted', function () {
    config(['debug_api.key' => 'test-key']);
    $relic = Relic::create(['key' => 'soon-deleted', 'name' => 'Soon Deleted']);
    CardComment::create([
        'commentable_type' => 'relic',
        'commentable_id' => $relic->id,
        'kind' => 'deletion',
        'body' => 'No longer needed.',
    ]);
    $relic->delete();

    $response = $this->withToken('test-key')->getJson('/api/dev-sync/comments');

    $returned = collect($response->json('comments'))->firstWhere('card.key', 'soon-deleted');
    expect($returned)->not->toBeNull();
});
