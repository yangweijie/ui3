<?php
declare(strict_types=1);

use Yangweijie\Ui3\{Element};
use Yangweijie\Ui3\Backends\Canvas;
use Yangweijie\Ui3\Canvas\Layout;

/**
 * Interaction tests for scroll-container scrolling:
 *  - keyboard arrow keys (after a pointer-down activates the container)
 *  - the WHEEL event the native host now forwards
 * Both must route through scrollContainerAt() + scrollBy() and keep clipping.
 */

test('arrow keys scroll the activated scroll container', function (): void {
    $items = array_map(
        static fn(int $i): Element => new Element('list_item', ['title' => "Item #{$i}"]),
        range(0, 29),
    );
    $root = new Element('window', ['width' => 320, 'height' => 240], [
        new Element('column', [], [
            new Element('label', ['text' => 'scroll']),
            new Element('scroll', ['id' => 'list', 'grow' => 1], $items),
        ]),
    ]);

    $backend = new Canvas(headless: true);
    $backend->mount($root, fn(): null => null);

    $scroll = null;
    foreach (Layout::compute($root) as $n) {
        if ($n->type === 'scroll') {
            $scroll = $n;
            break;
        }
    }
    expect($scroll)->not->toBeNull();

    // Activate the container by clicking inside its viewport (pointer-down).
    $backend->injectPointer($scroll->x + 5, $scroll->y + 10, true);
    $backend->step();
    expect($backend->scrollOffset('list'))->toBe(0);

    // Arrow Down scrolls the active container by 40px.
    $backend->injectKey("\x04");
    $backend->step();
    expect($backend->scrollOffset('list'))->toBe(40);

    // Arrow Up scrolls back to the top.
    $backend->injectKey("\x03");
    $backend->step();
    expect($backend->scrollOffset('list'))->toBe(0);
});

test('WHEEL event over the viewport scrolls it', function (): void {
    $items = array_map(
        static fn(int $i): Element => new Element('list_item', ['title' => "Item #{$i}"]),
        range(0, 29),
    );
    $root = new Element('window', ['width' => 320, 'height' => 240], [
        new Element('column', [], [
            new Element('label', ['text' => 'scroll']),
            new Element('scroll', ['id' => 'list', 'grow' => 1], $items),
        ]),
    ]);

    $backend = new Canvas(headless: true);
    $backend->mount($root, fn(): null => null);

    $scroll = null;
    foreach (Layout::compute($root) as $n) {
        if ($n->type === 'scroll') {
            $scroll = $n;
            break;
        }
    }

    // Drive the WHEEL branch directly (the native host forwards kind=5).
    $onEvent = new ReflectionMethod($backend, 'onEvent');
    $onEvent->invoke($backend, 5, $scroll->x + 5, $scroll->y + 10, 40.0, null);
    expect($backend->scrollOffset('list'))->toBe(40);

    $onEvent->invoke($backend, 5, $scroll->x + 5, $scroll->y + 10, -40.0, null);
    expect($backend->scrollOffset('list'))->toBe(0);
});

test('scrolling keeps overflow clipped to the viewport', function (): void {
    $items = array_map(
        static fn(int $i): Element => new Element('list_item', ['title' => "Item #{$i}"]),
        range(0, 29),
    );
    $root = new Element('window', ['width' => 320, 'height' => 240], [
        new Element('column', [], [
            new Element('label', ['text' => 'scroll']),
            new Element('scroll', ['id' => 'list', 'grow' => 1], $items),
        ]),
    ]);

    $backend = new Canvas(headless: true);
    $backend->mount($root, fn(): null => null);

    $scroll = null;
    foreach (Layout::compute($root) as $n) {
        if ($n->type === 'scroll') {
            $scroll = $n;
            break;
        }
    }

    $buf = $backend->offscreenPixels();
    $bg = $buf['px'][2][2];
    $belowY = (int) ($scroll->y + $scroll->h + 4);
    // Region just below the viewport must stay background (clipped) at offset 0.
    expect($buf['px'][$belowY][(int) ($scroll->x + 5)])->toBe($bg);

    // Scroll down and re-render: content moves but the overflow is still clipped.
    $backend->scrollBy('list', 120);
    $buf2 = $backend->offscreenPixels();
    expect($buf2['px'][$belowY][(int) ($scroll->x + 5)])->toBe($bg);
});

test('overflowing scroll container paints a scrollbar thumb', function (): void {
    $items = array_map(
        static fn(int $i): Element => new Element('list_item', ['title' => "Item #{$i}"]),
        range(0, 29),
    );
    $root = new Element('window', ['width' => 320, 'height' => 240], [
        new Element('column', [], [
            new Element('label', ['text' => 'scroll']),
            new Element('scroll', ['id' => 'list', 'grow' => 1], $items),
        ]),
    ]);

    $backend = new Canvas(headless: true);
    $backend->mount($root, fn(): null => null);

    $scroll = null;
    foreach (Layout::compute($root) as $n) {
        if ($n->type === 'scroll') {
            $scroll = $n;
            break;
        }
    }

    // Overlay scrollbar only appears after interaction (native behaviour): nudge it.
    $backend->scrollBy('list', 0);
    $buf = $backend->offscreenPixels();
    $px = $buf['px'];
    $thumb = $backend->col('scrollbarThumb');
    [$tr, $tg, $tb] = array_map(static fn(float $c): int => (int) ($c * 255), $thumb);

    // The scrollbar track/thumb sits at the right edge of the viewport.
    $tx = (int) ($scroll->x + $scroll->w - 8);
    $found = false;
    for ($y = (int) $scroll->y; $y < (int) ($scroll->y + $scroll->h); $y++) {
        $p = $px[$y][$tx];
        if (abs($p[0] - $tr) < 24 && abs($p[1] - $tg) < 24 && abs($p[2] - $tb) < 24) {
            $found = true;
            break;
        }
    }
    expect($found)->toBeTrue();
});

test('scrolling past the end clamps and rubber-bands back', function (): void {
    $items = array_map(
        static fn(int $i): Element => new Element('list_item', ['title' => "Item #{$i}"]),
        range(0, 29),
    );
    $root = new Element('window', ['width' => 320, 'height' => 240], [
        new Element('column', [], [
            new Element('label', ['text' => 'scroll']),
            new Element('scroll', ['id' => 'list', 'grow' => 1], $items),
        ]),
    ]);

    $backend = new Canvas(headless: true);
    $backend->mount($root, fn(): null => null);

    $scroll = null;
    foreach (Layout::compute($root) as $n) {
        if ($n->type === 'scroll') {
            $scroll = $n;
            break;
        }
    }
    $maxOff = Layout::scrollContentHeight('list') - (int) $scroll->h;

    // Scroll far beyond the end: committed offset must clamp, not overshoot.
    $backend->scrollBy('list', $maxOff + 500);
    expect($backend->scrollOffset('list'))->toBe($maxOff);

    // After the rubber-band settles (many paints), the committed offset is unchanged.
    for ($i = 0; $i < 80; $i++) {
        $backend->step();
    }
    expect($backend->scrollOffset('list'))->toBe($maxOff);
});

test('dragging the scrollbar thumb scrolls the content', function (): void {
    $items = array_map(
        static fn(int $i): Element => new Element('list_item', ['title' => "Item #{$i}"]),
        range(0, 29),
    );
    $root = new Element('window', ['width' => 320, 'height' => 240], [
        new Element('column', [], [
            new Element('label', ['text' => 'scroll']),
            new Element('scroll', ['id' => 'list', 'grow' => 1], $items),
        ]),
    ]);

    $backend = new Canvas(headless: true);
    $backend->mount($root, fn(): null => null);

    $scroll = null;
    foreach (Layout::compute($root) as $n) {
        if ($n->type === 'scroll') {
            $scroll = $n;
            break;
        }
    }
    $contentH = Layout::scrollContentHeight('list');
    $vh = (int) $scroll->h;
    $maxOff = max(0, $contentH - $vh);
    $thickness = 8; // matches Theme scrollbarThickness default
    $trackX = $scroll->x + $scroll->w - $thickness - 2;
    $trackY = $scroll->y + 2;
    $trackH = $vh - 4;
    $thumbH = max(16, (int) (($vh / $contentH) * $trackH));
    $thumbY = $trackY; // offset 0 => thumb at the top of the track

    $onEvent = new ReflectionMethod($backend, 'onEvent');
    $grabX = $trackX + $thickness / 2;
    $grabY = $thumbY + $thumbH / 2;

    // Pointer-down on the thumb grabs it but does not move the content yet.
    $onEvent->invoke($backend, 1, $grabX, $grabY, 0.0, null); // POINTER_DOWN
    expect($backend->scrollOffset('list'))->toBe(0);

    // Drag the thumb to the middle of the track: offset becomes maxOff/2.
    $targetY = $trackY + $trackH / 2;
    $onEvent->invoke($backend, 3, $grabX, $targetY, 0.0, null); // POINTER_MOVE
    expect($backend->scrollOffset('list'))->toBe((int) round($maxOff / 2));

    // Release.
    $onEvent->invoke($backend, 2, $grabX, $targetY, 0.0, null); // POINTER_UP
    expect($backend->scrollOffset('list'))->toBe((int) round($maxOff / 2));
});

test('clicking the scrollbar track jumps the thumb there', function (): void {
    $items = array_map(
        static fn(int $i): Element => new Element('list_item', ['title' => "Item #{$i}"]),
        range(0, 29),
    );
    $root = new Element('window', ['width' => 320, 'height' => 240], [
        new Element('column', [], [
            new Element('label', ['text' => 'scroll']),
            new Element('scroll', ['id' => 'list', 'grow' => 1], $items),
        ]),
    ]);

    $backend = new Canvas(headless: true);
    $backend->mount($root, fn(): null => null);

    $scroll = null;
    foreach (Layout::compute($root) as $n) {
        if ($n->type === 'scroll') {
            $scroll = $n;
            break;
        }
    }
    $contentH = Layout::scrollContentHeight('list');
    $vh = (int) $scroll->h;
    $maxOff = max(0, $contentH - $vh);
    $thickness = 8;
    $trackX = $scroll->x + $scroll->w - $thickness - 2;
    $trackY = $scroll->y + 2;
    $trackH = $vh - 4;
    $thumbH = max(16, (int) (($vh / $contentH) * $trackH));
    // Click in the track below the thumb (offset 0 => thumb at the top).
    $clickY = $trackY + $trackH * 0.8;

    $onEvent = new ReflectionMethod($backend, 'onEvent');
    $onEvent->invoke($backend, 1, $trackX + $thickness / 2, $clickY, 0.0, null); // POINTER_DOWN

    $jump = (int) ((($clickY - $trackY) * $maxOff) / max(1, $trackH - $thumbH));
    $expected = max(0, min($maxOff, $jump - (int) ($thumbH / 2)));
    expect($backend->scrollOffset('list'))->toBe($expected);
});
