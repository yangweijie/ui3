<?php

declare(strict_types=1);

use Yangweijie\Ui3\Backends\Canvas;
use Yangweijie\Ui3\Element;
use Yangweijie\Ui3\Ui;

/**
 * Phase 5 (Direction 6): events / input.
 *
 * Covers keyboard focus traversal, programmatic scroll (wheel-equivalent),
 * right-click context menu, and gestures — all drivable headless via the
 * backend API (the host injects the originating low-level events).
 */
test('Tab cycles focus through focusable widgets and wraps', function () {
    $b = Ui::button('OK', 'b:click', 'b1');
    $i = Ui::input('', 'type', 'i:input', 'i1');
    $c = Ui::checkbox('Agree', false, 'c:change', 'c1');

    $canvas = new Canvas(headless: true);
    $root = Ui::window('focus', [$b, $i, $c], 240, 160);
    $canvas->mount($root, fn() => null);
    $canvas->update($root);
    $canvas->requestRedraw();
    $canvas->step();

    expect($canvas->focusedId())->toBeNull();
    $canvas->tabForward();
    expect($canvas->focusedId())->toBe('b1');
    $canvas->tabForward();
    expect($canvas->focusedId())->toBe('i1');
    $canvas->tabForward();
    expect($canvas->focusedId())->toBe('c1');
    $canvas->tabForward();
    expect($canvas->focusedId())->toBe('b1'); // wraps to start
    $canvas->tabBackward();
    expect($canvas->focusedId())->toBe('c1'); // wraps to end
});

test('scrollBy moves list and scroll widgets and dispatches onScroll', function () {
    $items = array_map(static fn($n) => "item $n", range(0, 29));
    $list = Ui::with(
        Ui::list($items, -1, 'lst:select', 'lst'),
        ['virtual' => true, 'viewport' => 5, 'onScroll' => 'lst:scroll'],
    );
    $scroll = new Element('scroll', ['id' => 'scr', 'onScroll' => 'scr:scroll'], [
        Ui::label('a'), Ui::label('b'),
    ]);

    $canvas = new Canvas(headless: true);
    $root = Ui::window('scroll', [$list, $scroll], 240, 300);
    $captured = [];
    $canvas->mount($root, function ($m, ...$a) use (&$captured) {
        $captured[] = [$m, $a];
    });
    $canvas->update($root);
    $canvas->requestRedraw();
    $canvas->step();

    expect($canvas->scrollOffset('lst'))->toBe(0);
    $canvas->scrollBy('lst', 3);
    expect($canvas->scrollOffset('lst'))->toBe(3);
    expect($captured)->toContainEqual(['lst:scroll', [3]]);

    $canvas->scrollBy('lst', -10); // clamps at 0
    expect($canvas->scrollOffset('lst'))->toBe(0);

    $canvas->scrollBy('scr', 10);
    // 'scr' content fits its viewport (maxOff = 0), so scrolling clamps to 0.
    expect($canvas->scrollOffset('scr'))->toBe(0);
});

test('context menu opens, exposes items, and closes', function () {
    $el = Ui::contextMenu(Ui::button('Right', 'b:click', 'ctxbtn'), [
        ['title' => 'Copy', 'msg' => 'ctx:copy'],
        ['title' => 'Paste', 'msg' => 'ctx:paste'],
    ]);

    $canvas = new Canvas(headless: true);
    $root = Ui::window('ctx', [$el], 240, 120);
    $canvas->mount($root, fn() => null);
    $canvas->update($root);
    $canvas->requestRedraw();
    $canvas->step();

    expect($canvas->isContextMenuOpen('ctxbtn'))->toBeFalse();
    $canvas->openContextMenu('ctxbtn');
    expect($canvas->isContextMenuOpen('ctxbtn'))->toBeTrue();
    expect($canvas->contextMenuItems('ctxbtn'))->toHaveCount(2);
    expect($canvas->contextMenuItems('ctxbtn')[0]['msg'])->toBe('ctx:copy');

    $canvas->closeContextMenus();
    expect($canvas->isContextMenuOpen('ctxbtn'))->toBeFalse();
});

test('dispatchGesture fires the onGesture message and ignores mismatches', function () {
    $el = Ui::gesture(Ui::button('Swipe', 'b:click', 'gbtn'), 'swipe', 'g:swipe');

    $canvas = new Canvas(headless: true);
    $root = Ui::window('g', [$el], 240, 120);
    $captured = [];
    $canvas->mount($root, function ($m, ...$a) use (&$captured) {
        $captured[] = [$m, $a];
    });
    $canvas->update($root);
    $canvas->requestRedraw();
    $canvas->step();

    $canvas->dispatchGesture('gbtn', 'swipe');
    expect($captured)->toContainEqual(['g:swipe', ['swipe']]);

    $canvas->dispatchGesture('gbtn', 'pinch'); // not registered
    expect($captured)->toHaveCount(1);
});
