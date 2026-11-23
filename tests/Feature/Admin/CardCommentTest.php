<?php

use App\Models\Cards\CardComment;
use App\Models\Cards\Relic;

// adminUser() is declared in the sibling LocationCrudTest.php, loaded in
// the same Pest run.

test('an admin can add a comment to a card and later resolve it', function () {
    $admin = adminUser();
    $relic = Relic::create(['key' => 'test-relic', 'name' => 'Test Relic']);

    $this->actingAs($admin)->post('/admin/comments', [
        'commentable_type' => 'relic',
        'commentable_id' => $relic->id,
        'body' => 'This effect needs to be re-implemented.',
    ])->assertRedirect();

    $comment = CardComment::first();
    expect($comment->body)->toBe('This effect needs to be re-implemented.')
        ->and($comment->kind)->toBe('comment')
        ->and($comment->resolved_at)->toBeNull()
        ->and($comment->created_by)->toBe($admin->id);

    $this->actingAs($admin)->put("/admin/comments/{$comment->id}/resolve", [
        'resolved_note' => 'Fixed in GameEngine::resolveRelicAction.',
    ])->assertRedirect();

    $comment->refresh();
    expect($comment->resolved_at)->not->toBeNull()
        ->and($comment->resolved_note)->toBe('Fixed in GameEngine::resolveRelicAction.');
});

test('commenting requires a valid commentable_type', function () {
    $admin = adminUser();
    $relic = Relic::create(['key' => 'test-relic-2', 'name' => 'Test Relic 2']);

    $this->actingAs($admin)->post('/admin/comments', [
        'commentable_type' => 'not-a-real-type',
        'commentable_id' => $relic->id,
        'body' => 'Should not be allowed.',
    ])->assertSessionHasErrors('commentable_type');
});
