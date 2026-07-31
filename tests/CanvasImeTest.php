<?php
declare(strict_types=1);

use Yangweijie\Ui3\App;
use Yangweijie\Ui3\Automation\Automation;
use Yangweijie\Ui3\Backends\Canvas;
use Yangweijie\Ui3\Ui;

/**
 * P3-B: Canvas (native backend) IME composition parity.
 *
 * The Reference headless backend already renders the candidate preview (pixel-
 * tested in ImeTest). This verifies the native Canvas path:
 *   1. Stores the same composition state as Reference.
 *   2. The composition preview render is visible via offscreenPixels.
 *   3. App::composition routes correctly to Canvas.
 */
// ---- State-level tests ----

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

// ---- Pixel-level tests (via offscreenPixels through the compositor) ----

test('canvas composition preview renders via offscreenPixels', function () {
    $c = new Canvas(headless: true);
    $c->mount(Ui::window('F', [Ui::input('', 'type', null, 'field-1')]), static fn() => null);

    $before = $c->offscreenPixels();

    $c->composition('field-1', 'update', 'あい');
    $after = $c->offscreenPixels();

    // The preview text ('あい') rendered as accent-coloured underlined text
    // must change the pixel output vs the empty-field state. Compare hashes
    // to avoid PHPUnit exploding the 320x240 array on diff.
    expect(md5(serialize($before['px'])))->not->toBe(md5(serialize($after['px'])));
});

test('canvas composition end clears preview in pixels', function () {
    $c = new Canvas(headless: true);
    $c->mount(Ui::window('F', [Ui::input('', 'type', null, 'field-1')]), static fn() => null);

    $before = $c->offscreenPixels();
    $c->composition('field-1', 'update', 'あい');
    $c->composition('field-1', 'end', '');
    $cleared = $c->offscreenPixels();

    // After composition end, the pixel output should match the original
    // empty-field state (no preview residue).
    expect(md5(serialize($before['px'])))->toBe(md5(serialize($cleared['px'])));
});

// ---- End-to-end App::composition routing ----

test('app composition routes to canvas and updates pixels', function () {
    $app = new App(
        init: fn (): array => [],
        update: fn (array $m, string $msg, mixed $p = null): array => $m,
        view: fn (array $m): \Yangweijie\Ui3\Element =>
            Ui::window('F', [Ui::input('', 'type', null, 'field-1')], width: 320, height: 240),
    );

    $auto = new Automation($app, new Canvas(headless: true));
    $auto->start();

    $before = $auto->backend()->offscreenPixels();

    $app->composition('field-1', 'update', 'あい');
    $after = $auto->backend()->offscreenPixels();

    expect(md5(serialize($before['px'])))->not->toBe(md5(serialize($after['px'])));
    expect($after['w'])->toBe(320);
    expect($after['h'])->toBe(240);
});

// ---- Helpers ----

/**
 * @return array{phase:string,text:string}|null
 */
function compositionState(Canvas $c, string $id): ?array
{
    $prop = new \ReflectionProperty(Canvas::class, 'composition');
    $all = $prop->getValue($c);
    return $all[$id] ?? null;
}
