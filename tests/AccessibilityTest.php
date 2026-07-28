<?php

declare(strict_types=1);

use Yangweijie\Ui3\Automation\Snapshot;
use Yangweijie\Ui3\Ui;

/**
 * Phase 7 (Direction 7): accessibility.
 *
 * Covers explicit semantic role/label/description on elements, the focused
 * flag in the snapshot, and that the accessible name overrides the derived one.
 */
test('snapshot exposes semantic role, label and description', function () {
    $btn = Ui::accessible(
        Ui::button('Save', 'save:click', 'save-btn'),
        'button',
        'Save the document',
        'Writes the current document to disk',
    );
    $root = Ui::window('a11y', [$btn], 200, 120);
    $snap = Snapshot::capture($root);

    $w = Snapshot::findById($snap, 'save-btn');
    expect($w)->not->toBeNull();
    expect($w['role'])->toBe('button');
    expect($w['label'])->toBe('Save the document');
    expect($w['name'])->toBe('Save the document'); // label overrides derived name
    expect($w['description'])->toBe('Writes the current document to disk');
});

test('explicit role overrides the widget type in the snapshot', function () {
    // A plain label presented to assistive tech as a heading.
    $heading = Ui::accessible(Ui::label('Summary'), 'heading');
    $root = Ui::window('h', [$heading], 200, 120);
    $snap = Snapshot::capture($root);

    $w = Snapshot::findByRole($snap, 'heading');
    expect($w)->toHaveCount(1);
    expect($w[0]['name'])->toBe('Summary'); // derived from the label text
});

test('snapshot marks the focused widget', function () {
    $a = Ui::button('A', 'a:click', 'a');
    $b = Ui::button('B', 'b:click', 'b');
    $root = Ui::window('f', [$a, $b], 200, 120);

    $focused = Snapshot::capture($root, 200, 120, [], 'b');
    $wb = Snapshot::findById($focused, 'b');
    $wa = Snapshot::findById($focused, 'a');
    expect($wb['focused'])->toBeTrue();
    expect($wa['focused'])->toBeFalse();

    $unfocused = Snapshot::capture($root, 200, 120, [], null);
    expect(Snapshot::findById($unfocused, 'b')['focused'])->toBeFalse();
});
