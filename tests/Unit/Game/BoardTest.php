<?php

use App\Game\Board;

test('neighbor math is reversible in both directions', function () {
    foreach (Board::DIRECTIONS as $direction) {
        $cell = [3, 2];
        $neighbor = Board::neighbor($cell, $direction);

        expect(Board::directionBetween($cell, $neighbor))->toBe($direction);
    }
});

test('starting cells are all mutually adjacent enough to form one cluster', function () {
    $cells = Board::startingCells();
    $occupied = array_fill_keys(array_map(Board::key(...), $cells), true);

    // every starting cell must be reachable from every other one
    foreach ($cells as $a) {
        foreach ($cells as $b) {
            expect(Board::distance($a, $b, $occupied))->not->toBeNull();
        }
    }
});

test('placeableCells returns only empty cells adjacent to the occupied set', function () {
    $occupied = ['0,0' => true];
    $placeable = array_map(Board::key(...), Board::placeableCells($occupied));

    expect($placeable)->toHaveCount(6)
        ->and($placeable)->not->toContain('0,0');
});

test('shortestPath finds the direct hop between adjacent cells', function () {
    $occupied = ['0,0' => true, '0,1' => true];

    $path = Board::shortestPath([0, 0], [0, 1], $occupied);

    expect($path)->toBe([[0, 1]]);
});

test('shortestPath returns null when the target is unreachable', function () {
    $occupied = ['0,0' => true, '5,5' => true];

    expect(Board::shortestPath([0, 0], [5, 5], $occupied))->toBeNull();
});

test('shortestPath honors excluded cells (Walls blocking the Golem)', function () {
    $occupied = ['0,0' => true, '1,0' => true];

    expect(Board::shortestPath([0, 0], [1, 0], $occupied, excluded: ['1,0']))->toBeNull();
});

test('cellsWithinDistance includes the center and expands outward', function () {
    $occupied = array_fill_keys(array_map(Board::key(...), Board::startingCells()), true);

    $within0 = Board::cellsWithinDistance([1, 0], 0, $occupied);
    expect($within0)->toHaveCount(1);

    $within1 = Board::cellsWithinDistance([1, 0], 1, $occupied);
    // [1,0] is adjacent to all 5 other starting cells (it's the hub)
    expect($within1)->toHaveCount(6);
});
