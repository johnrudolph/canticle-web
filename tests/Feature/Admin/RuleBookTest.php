<?php

use App\Models\Cards\RuleBook;
use App\Models\Cards\RuleBookRevision;

// adminUser() is declared in LocationCrudTest.php, loaded in the same run.

test('saving the rulebook with no actual change requires no justification', function () {
    $admin = adminUser();
    RuleBook::current()->update(['content' => 'Original rules.']);

    $this->actingAs($admin)->put('/admin/rulebook', [
        'content' => 'Original rules.',
    ])->assertRedirect();

    expect(RuleBookRevision::count())->toBe(0);
});

test('saving a real rulebook change without a justification is rejected', function () {
    $admin = adminUser();
    RuleBook::current()->update(['content' => 'Original rules.']);

    $this->actingAs($admin)->put('/admin/rulebook', [
        'content' => 'Changed rules about roads.',
    ])->assertSessionHasErrors('justification');

    expect(RuleBook::current()->content)->toBe('Original rules.');
});

test('saving a real rulebook change with a justification creates a revision and updates the content', function () {
    $admin = adminUser();
    RuleBook::current()->update(['content' => 'Roads: move into a Road space for free.']);

    $this->actingAs($admin)->put('/admin/rulebook', [
        'content' => 'Roads: move OUT of a Road space for free.',
        'justification' => 'The old wording let you chain free moves indefinitely.',
    ])->assertRedirect();

    expect(RuleBook::current()->content)->toBe('Roads: move OUT of a Road space for free.');

    $revision = RuleBookRevision::first();
    expect($revision->justification)->toBe('The old wording let you chain free moves indefinitely.')
        ->and($revision->content)->toBe('Roads: move OUT of a Road space for free.')
        ->and($revision->created_by)->toBe($admin->id)
        ->and($revision->resolved_at)->toBeNull();
});
