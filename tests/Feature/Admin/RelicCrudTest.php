<?php

use App\Models\Cards\Relic;

// adminUser() is declared in the sibling LocationCrudTest.php, loaded in
// the same run.

test('creating a relic computes slot_emoji from slot and is_action/is_passive/is_event from type', function () {
    $admin = adminUser();

    $this->actingAs($admin)->post('/admin/relics', [
        'name' => 'Test Hand Relic',
        'print' => false,
        'slot' => 'Hand',
        'type' => 'Passive',
        'symbol' => '🎨',
        'effect' => 'Does a thing.',
    ])->assertRedirect();

    $relic = Relic::where('name', 'Test Hand Relic')->first();

    expect($relic->slot_emoji)->toBe('🖐️')
        ->and($relic->is_action)->toBeFalse()
        ->and($relic->is_passive)->toBeTrue()
        ->and($relic->is_event)->toBeFalse();
});

test('an invalid relic type or slot is rejected', function () {
    $admin = adminUser();

    $this->actingAs($admin)->post('/admin/relics', [
        'name' => 'Bad Relic',
        'slot' => 'Hand',
        'type' => 'NotARealType',
        'symbol' => '🎨',
    ])->assertSessionHasErrors('type');

    $this->actingAs($admin)->post('/admin/relics', [
        'name' => 'Bad Relic 2',
        'slot' => 'NotARealSlot',
        'type' => 'Action',
        'symbol' => '🎨',
    ])->assertSessionHasErrors('slot');
});

test('a relic cannot be created without a type — it is required, never null', function () {
    $admin = adminUser();

    $this->actingAs($admin)->post('/admin/relics', [
        'name' => 'Typeless Relic',
        'slot' => 'Hand',
    ])->assertSessionHasErrors('type');

    expect(Relic::where('name', 'Typeless Relic')->exists())->toBeFalse();
});

test('updating a relic recomputes slot_emoji and the type flags', function () {
    $admin = adminUser();
    $relic = Relic::create([
        'key' => 'switchable-relic',
        'name' => 'Switchable Relic',
        'slot' => 'Hand',
        'slot_emoji' => '🖐️',
        'type' => 'Action',
        'is_action' => true,
        'is_passive' => false,
        'is_event' => false,
    ]);

    $this->actingAs($admin)->put("/admin/relics/{$relic->id}", [
        'name' => 'Switchable Relic',
        'slot' => 'Body',
        'type' => 'Event',
        'symbol' => '',
    ])->assertRedirect();

    $relic->refresh();
    expect($relic->slot_emoji)->toBe('🥋')
        ->and($relic->is_action)->toBeFalse()
        ->and($relic->is_event)->toBeTrue();
});

test('the relics index page includes a slot x type count matrix', function () {
    $admin = adminUser();

    // The test DB is seeded with the real card set, so assert against
    // the baseline + what this test adds, not an absolute count.
    $before = Relic::where('slot', 'Hand')->where('type', 'Action')->count();

    Relic::create(['key' => 'matrix-a', 'name' => 'Matrix A', 'slot' => 'Hand', 'type' => 'Action']);
    Relic::create(['key' => 'matrix-b', 'name' => 'Matrix B', 'slot' => 'Hand', 'type' => 'Action']);

    $response = $this->actingAs($admin)->get('/admin/relics');

    $response->assertInertia(fn ($page) => $page
        ->where('relicSummary.matrix.Hand.Action', $before + 2)
    );
});
