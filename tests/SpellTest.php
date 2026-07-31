<?php
declare(strict_types=1);

use Yangweijie\Ui3\Backends\Reference;
use Yangweijie\Ui3\Ui;

/**
 * P-Native P2: 拼写检查 (spell check) — Reference backend pixel parity.
 *
 * The Reference GD renderer must draw red wavy underlines under misspelled
 * words when the input carries the spellcheck prop, and stay pixel-identical
 * to the non-spellcheck render when all words are known.
 */
test('reference draws squiggles under misspelled text when spellcheck is enabled', function () {
    $with = new Reference(320, 240);
    $with->mount(Ui::window('F', [Ui::input('brwn', 'value', 'v', 'field-1', spellcheck: true)]), static fn() => null);

    $without = new Reference(320, 240);
    $without->mount(Ui::window('F', [Ui::input('brwn', 'value', 'v', 'field-1')]), static fn() => null);

    expect($with->pixelsHash())->not->toBe($without->pixelsHash());
});

test('reference draws no squiggles for correctly spelled text', function () {
    $with = new Reference(320, 240);
    $with->mount(Ui::window('F', [Ui::input('brown', 'value', 'v', 'field-1', spellcheck: true)]), static fn() => null);

    $without = new Reference(320, 240);
    $without->mount(Ui::window('F', [Ui::input('brown', 'value', 'v', 'field-1')]), static fn() => null);

    expect($with->pixelsHash())->toBe($without->pixelsHash());
});

test('reference search field draws squiggles too', function () {
    $with = new Reference(320, 240);
    $with->mount(Ui::window('F', [Ui::searchField('brwn', 'value', 'v', 'field-1', spellcheck: true)]), static fn() => null);

    $without = new Reference(320, 240);
    $without->mount(Ui::window('F', [Ui::searchField('brwn', 'value', 'v', 'field-1')]), static fn() => null);

    expect($with->pixelsHash())->not->toBe($without->pixelsHash());
});
