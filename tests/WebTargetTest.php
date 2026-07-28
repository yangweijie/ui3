<?php

declare(strict_types=1);

use Yangweijie\Ui3\Backends\Html;
use Yangweijie\Ui3\Ui;

/**
 * Phase 9 (Direction 9): Web target (Html backend).
 *
 * Verifies the Html backend renders the Element tree to semantic HTML with
 * roles/ids/labels and theme-driven colors, sharing the automation contract
 * (data-role / data-id) with the Canvas backend.
 */
test('Html backend renders semantic markup with roles, ids and labels', function () {
    $btn = Ui::accessible(Ui::button('Save', 'save:click', 'save-btn'), 'button', 'Save the document');
    $root = Ui::window('web', [
        Ui::label('Name', 'name-lbl'),
        Ui::input('', 'Your name', 'name:input', 'name-in'),
        $btn,
    ], 320, 240);

    $html = new Html();
    $html->mount($root, fn() => null);

    $out = $html->html();
    expect($out)->toContain('data-role="window"');
    expect($out)->toContain('data-role="button"');
    expect($out)->toContain('data-id="save-btn"');
    expect($out)->toContain('data-label="Save the document"');
    expect($out)->toContain('>Save<');
    expect($out)->toContain('data-role="input"');
    expect($out)->toContain('type="text"');
    expect($out)->toContain('placeholder="Your name"');
    // theme color applied
    expect($out)->toContain('background:rgb(');
});

test('Html backend re-renders on update', function () {
    $root1 = Ui::window('w', [Ui::label('A')], 200, 120);
    $root2 = Ui::window('w', [Ui::label('B')], 200, 120);

    $html = new Html();
    $html->mount($root1, fn() => null);
    expect($html->html())->toContain('>A<');

    $html->update($root2);
    expect($html->html())->toContain('>B<');
    expect($html->html())->not->toContain('>A<');
});

test('Html backend is headless and exposes layout for snapshots', function () {
    $root = Ui::window('w', [Ui::button('OK', 'ok:click', 'ok')], 200, 120);
    $html = new Html();
    $html->mount($root, fn() => null);

    expect($html->isHeadless())->toBeTrue();
    expect($html->layout())->not->toBeEmpty();
});
