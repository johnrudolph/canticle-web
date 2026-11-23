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
