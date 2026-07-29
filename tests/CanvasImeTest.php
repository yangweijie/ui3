<?php
declare(strict_types=1);

use Yangweijie\Ui3\Backends\Canvas;
use Yangweijie\Ui3\Ui;

/**
 * P3-B: Canvas (native backend) IME composition parity.
 *
 * The Reference headless backend already renders the candidate preview (pixel-
 * tested in ImeTest). This verifies the native Canvas path stores the same
 * composition state and exposes the same `composition()` entry point App routes
 * to, so headless and native behave identically.
 */
test('canvas stores and clears IME composition state', function () {
    $c = new Canvas(headless: true);
    $c->mount(Ui::window('F', [Ui::input('', 'type', null, 'field-1')]), static fn() => null);

    $c->composition('field-1', 'update', 'あい');
    $state = compositionState($c, 'field-1');
    expect($state)->not->toBeNull();
    expect($state['text'])->toBe('あい');
    expect($state['phase'])->toBe('update');

    $c->composition('field-1', 'end', '');
    expect(compositionState($c, 'field-1'))->toBeNull();
});

test('canvas composition end is idempotent', function () {
    $c = new Canvas(headless: true);
    $c->mount(Ui::window('F', [Ui::input('', 'type', null, 'field-1')]), static fn() => null);

    // Clearing a field that was never composed must not leave stale state.
    $c->composition('field-1', 'end', '');
    expect(compositionState($c, 'field-1'))->toBeNull();
});

/**
 * @return array{phase:string,text:string}|null
 */
function compositionState(Canvas $c, string $id): ?array
{
    $prop = new \ReflectionProperty(Canvas::class, 'composition');
    $all = $prop->getValue($c);
    return $all[$id] ?? null;
}
