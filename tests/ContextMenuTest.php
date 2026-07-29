<?php
declare(strict_types=1);

use Yangweijie\Ui3\App;
use Yangweijie\Ui3\Ui;
use Yangweijie\Ui3\Backends\Canvas;
use Yangweijie\Ui3\Automation\Automation;
use Yangweijie\Ui3\Automation\Snapshot;

// Normalized pointer buttons (mirrors Canvas::MB_LEFT / Canvas::MB_RIGHT).
const CM_LEFT = 1;
const CM_RIGHT = 2;

test('right-click on a text field opens the built-in edit menu', function () {
    $auto = (new Automation(editApp(), new Canvas(headless: true)))->start();
    $auto->focus('v-input');
    $auto->type('hello');

    $w = Snapshot::findById($auto->snapshot(), 'v-input');
    $cx = $w['x'] + $w['w'] / 2;
    $cy = $w['y'] + $w['h'] / 2;
    $auto->backend()->injectPointer($cx, $cy, true, CM_RIGHT);
    $auto->backend()->injectPointer($cx, $cy, false, CM_RIGHT);
    $auto->step();

    expect($auto->backend()->isContextMenuOpen('v-input'))->toBeTrue();
    $items = $auto->backend()->contextMenuItems('v-input');
    expect($items)->toHaveCount(7);
    expect($items[0]['preview'])->toBe('clipboard');
    expect($items[1]['title'])->toBe('Undo');
    expect($items[4]['title'])->toBe('Copy');
    expect($items[6]['title'])->toBe('Select All');
    expect($items[4])->toHaveKey('action');
});

test('clicking Copy in the edit menu copies the current selection', function () {
    $auto = (new Automation(editApp(), new Canvas(headless: true)))->start();
    $auto->focus('v-input');
    $auto->type('hello');
    $auto->pressKey('Ctrl+a'); // select all

    $w = Snapshot::findById($auto->snapshot(), 'v-input');
    $cx = $w['x'] + $w['w'] / 2;
    $cy = $w['y'] + $w['h'] / 2;
    // Open the menu.
    $auto->backend()->injectPointer($cx, $cy, true, CM_RIGHT);
    $auto->backend()->injectPointer($cx, $cy, false, CM_RIGHT);
    $auto->step();
    expect($auto->backend()->isContextMenuOpen('v-input'))->toBeTrue();

    // Click the Copy row (index 4; index 0 is the clipboard preview).
    [$mx, $my, $mw, $mh] = $auto->backend()->contextMenuItemRect('v-input', 4);
    $auto->backend()->injectPointer($mx + $mw / 2, $my + $mh / 2, true, CM_LEFT);
    $auto->backend()->injectPointer($mx + $mw / 2, $my + $mh / 2, false, CM_LEFT);
    $auto->step();

    expect($auto->backend()->clipboard())->toBe('hello');
    expect($auto->backend()->isContextMenuOpen('v-input'))->toBeFalse();
});

test('Escape dismisses an open context menu', function () {
    $auto = (new Automation(editApp(), new Canvas(headless: true)))->start();
    $auto->focus('v-input');
    $auto->type('hello');

    $w = Snapshot::findById($auto->snapshot(), 'v-input');
    $cx = $w['x'] + $w['w'] / 2;
    $cy = $w['y'] + $w['h'] / 2;
    $auto->backend()->injectPointer($cx, $cy, true, CM_RIGHT);
    $auto->backend()->injectPointer($cx, $cy, false, CM_RIGHT);
    $auto->step();
    expect($auto->backend()->isContextMenuOpen('v-input'))->toBeTrue();

    $auto->rawKey(53); // Escape keycode -> canonical \x1b token
    expect($auto->backend()->isContextMenuOpen('v-input'))->toBeFalse();
});

test('right-click on an element with a contextMenu prop opens that custom menu', function () {
    $app = new App(
        init: fn() => [],
        update: fn(array $m, string $msg, mixed $p = null) => $m,
        view: fn() => Ui::window('Ctx', [
            Ui::contextMenu(Ui::button('Right', 'b:click', 'ctxbtn'), [
                ['title' => 'Copy', 'msg' => 'ctx:copy'],
                ['title' => 'Paste', 'msg' => 'ctx:paste'],
            ]),
        ], width: 300, height: 160),
    );
    $auto = (new Automation($app, new Canvas(headless: true)))->start();

    $w = Snapshot::findById($auto->snapshot(), 'ctxbtn');
    $cx = $w['x'] + $w['w'] / 2;
    $cy = $w['y'] + $w['h'] / 2;
    $auto->backend()->injectPointer($cx, $cy, true, CM_RIGHT);
    $auto->backend()->injectPointer($cx, $cy, false, CM_RIGHT);
    $auto->step();

    expect($auto->backend()->isContextMenuOpen('ctxbtn'))->toBeTrue();
    $items = $auto->backend()->contextMenuItems('ctxbtn');
    expect($items)->toHaveCount(2);
    expect($items[0]['title'])->toBe('Copy');
    expect($items[0]['msg'])->toBe('ctx:copy');
});

test('hovering a menu row highlights it', function () {
    $auto = (new Automation(editApp(), new Canvas(headless: true)))->start();
    $auto->focus('v-input');
    $auto->type('hello');

    $w = Snapshot::findById($auto->snapshot(), 'v-input');
    $cx = $w['x'] + $w['w'] / 2;
    $cy = $w['y'] + $w['h'] / 2;
    $auto->backend()->injectPointer($cx, $cy, true, CM_RIGHT);
    $auto->backend()->injectPointer($cx, $cy, false, CM_RIGHT);
    $auto->step();

    // Hover the Copy row (index 4).
    [$mx, $my, $mw, $mh] = $auto->backend()->contextMenuItemRect('v-input', 4);
    $auto->backend()->injectPointerMove($mx + $mw / 2, $my + $mh / 2);
    $auto->step();
    expect($auto->backend()->contextMenuHover('v-input'))->toBe(4);

    // Move away from the menu -> nothing highlighted.
    $auto->backend()->injectPointerMove(5, 5);
    $auto->step();
    expect($auto->backend()->contextMenuHover('v-input'))->toBe(-1);
});

test('a submenu opens on hover and its items are selectable', function () {
    $captured = [];
    $app = new App(
        init: fn() => [],
        update: function (array $m, string $msg, mixed $p = null) use (&$captured) {
            $captured[] = $msg;
            return $m;
        },
        view: fn() => Ui::window('Ctx', [
            Ui::contextMenu(Ui::button('Right', 'b:click', 'ctxbtn'), [
                ['title' => 'Copy', 'msg' => 'ctx:copy'],
                ['title' => 'More', 'submenu' => [
                    ['title' => 'Sub A', 'msg' => 'ctx:suba'],
                    ['title' => 'Sub B', 'msg' => 'ctx:subb'],
                ]],
            ]),
        ], width: 300, height: 160),
    );
    $auto = (new Automation($app, new Canvas(headless: true)))->start();

    $w = Snapshot::findById($auto->snapshot(), 'ctxbtn');
    $cx = $w['x'] + $w['w'] / 2;
    $cy = $w['y'] + $w['h'] / 2;
    $auto->backend()->injectPointer($cx, $cy, true, CM_RIGHT);
    $auto->backend()->injectPointer($cx, $cy, false, CM_RIGHT);
    $auto->step();

    // Hover the "More" parent (index 1) -> submenu opens.
    [$mx, $my, $mw, $mh] = $auto->backend()->contextMenuItemRect('ctxbtn', 1);
    $auto->backend()->injectPointerMove($mx + $mw / 2, $my + $mh / 2);
    $auto->step();

    $sub = $auto->backend()->contextSubmenuItems('ctxbtn');
    expect($sub)->toHaveCount(2);
    expect($sub[0]['title'])->toBe('Sub A');

    // Click the first submenu item.
    [$sx, $sy, $sw, $sh] = $auto->backend()->contextSubmenuItemRect('ctxbtn', 0);
    $auto->backend()->injectPointer($sx + $sw / 2, $sy + $sh / 2, true, CM_LEFT);
    $auto->backend()->injectPointer($sx + $sw / 2, $sy + $sh / 2, false, CM_LEFT);
    $auto->step();

    expect($captured)->toContain('ctx:suba');
    expect($auto->backend()->isContextMenuOpen('ctxbtn'))->toBeFalse();
});

test('the clipboard preview row reflects live clipboard contents', function () {
    $auto = (new Automation(editApp(), new Canvas(headless: true)))->start();
    $auto->focus('v-input');
    $auto->type('hello');

    $w = Snapshot::findById($auto->snapshot(), 'v-input');
    $cx = $w['x'] + $w['w'] / 2;
    $cy = $w['y'] + $w['h'] / 2;
    $auto->backend()->injectPointer($cx, $cy, true, CM_RIGHT);
    $auto->backend()->injectPointer($cx, $cy, false, CM_RIGHT);
    $auto->step();

    $items = $auto->backend()->contextMenuItems('v-input');
    expect($items[0]['preview'])->toBe('clipboard');

    $auto->backend()->setClipboard('short text');
    expect($auto->backend()->contextMenuPreviewText('v-input', 0))->toBe('short text');

    $auto->backend()->setClipboard('');
    expect($auto->backend()->contextMenuPreviewText('v-input', 0))->toBe('(empty)');

    $long = str_repeat('x', 100);
    $auto->backend()->setClipboard($long);
    expect($auto->backend()->contextMenuPreviewText('v-input', 0))
        ->toBe(mb_substr($long, 0, 40) . '…');

    // A preview row is not a command: clicking it keeps the menu open.
    [$mx, $my, $mw, $mh] = $auto->backend()->contextMenuItemRect('v-input', 0);
    $auto->backend()->injectPointer($mx + $mw / 2, $my + $mh / 2, true, CM_LEFT);
    $auto->backend()->injectPointer($mx + $mw / 2, $my + $mh / 2, false, CM_LEFT);
    $auto->step();
    expect($auto->backend()->isContextMenuOpen('v-input'))->toBeTrue();
});

test('submenus nest to multiple levels on hover', function () {
    $app = new App(
        init: fn() => [],
        update: fn(array $m, string $msg, mixed $p = null) => $m,
        view: fn() => Ui::window('Ctx', [
            Ui::contextMenu(Ui::button('Right', 'b:click', 'ctxbtn'), [
                ['title' => 'Copy', 'msg' => 'ctx:copy'],
                ['title' => 'More', 'submenu' => [
                    ['title' => 'Sub A', 'submenu' => [
                        ['title' => 'Deep X', 'msg' => 'ctx:deepx'],
                        ['title' => 'Deep Y', 'msg' => 'ctx:deepy'],
                    ]],
                    ['title' => 'Sub B', 'msg' => 'ctx:subb'],
                ]],
            ]),
        ], width: 300, height: 160),
    );
    $auto = (new Automation($app, new Canvas(headless: true)))->start();

    $w = Snapshot::findById($auto->snapshot(), 'ctxbtn');
    $cx = $w['x'] + $w['w'] / 2;
    $cy = $w['y'] + $w['h'] / 2;
    $auto->backend()->injectPointer($cx, $cy, true, CM_RIGHT);
    $auto->backend()->injectPointer($cx, $cy, false, CM_RIGHT);
    $auto->step();
    expect($auto->backend()->contextSubmenuDepth('ctxbtn'))->toBe(0);

    // Hover "More" (root index 1) -> first level opens.
    [$mx, $my, $mw, $mh] = $auto->backend()->contextMenuItemRect('ctxbtn', 1);
    $auto->backend()->injectPointerMove($mx + $mw / 2, $my + $mh / 2);
    $auto->step();
    expect($auto->backend()->contextSubmenuDepth('ctxbtn'))->toBe(1);
    expect($auto->backend()->contextSubmenuItems('ctxbtn')[0]['title'])->toBe('Sub A');

    // Hover "Sub A" (level-1 row 0) -> second level opens.
    [$sx, $sy, $sw, $sh] = $auto->backend()->contextSubmenuItemRect('ctxbtn', 0);
    $auto->backend()->injectPointerMove($sx + $sw / 2, $sy + $sh / 2);
    $auto->step();
    expect($auto->backend()->contextSubmenuDepth('ctxbtn'))->toBe(2);
    expect($auto->backend()->contextSubmenuItems('ctxbtn')[0]['title'])->toBe('Deep X');

    // The ancestor (level 1) is still open underneath.
    expect($auto->backend()->contextSubmenuLevelItems('ctxbtn', 1)[1]['title'])->toBe('Sub B');

    // Moving back onto the root closes every descendant.
    [$rx, $ry, $rw, $rh] = $auto->backend()->contextMenuItemRect('ctxbtn', 0);
    $auto->backend()->injectPointerMove($rx + $rw / 2, $ry + $rh / 2);
    $auto->step();
    expect($auto->backend()->contextSubmenuDepth('ctxbtn'))->toBe(0);
});

test('clicking a nested item runs its message and closes the menu', function () {
    $captured = [];
    $app = new App(
        init: fn() => [],
        update: function (array $m, string $msg, mixed $p = null) use (&$captured) {
            $captured[] = $msg;
            return $m;
        },
        view: fn() => Ui::window('Ctx', [
            Ui::contextMenu(Ui::button('Right', 'b:click', 'ctxbtn'), [
                ['title' => 'More', 'submenu' => [
                    ['title' => 'Sub A', 'submenu' => [
                        ['title' => 'Deep X', 'msg' => 'ctx:deepx'],
                    ]],
                ]],
            ]),
        ], width: 300, height: 160),
    );
    $auto = (new Automation($app, new Canvas(headless: true)))->start();

    $w = Snapshot::findById($auto->snapshot(), 'ctxbtn');
    $cx = $w['x'] + $w['w'] / 2;
    $cy = $w['y'] + $w['h'] / 2;
    $auto->backend()->injectPointer($cx, $cy, true, CM_RIGHT);
    $auto->backend()->injectPointer($cx, $cy, false, CM_RIGHT);
    $auto->step();

    [$mx, $my, $mw, $mh] = $auto->backend()->contextMenuItemRect('ctxbtn', 0);
    $auto->backend()->injectPointerMove($mx + $mw / 2, $my + $mh / 2);
    $auto->step();
    [$sx, $sy, $sw, $sh] = $auto->backend()->contextSubmenuItemRect('ctxbtn', 0);
    $auto->backend()->injectPointerMove($sx + $sw / 2, $sy + $sh / 2);
    $auto->step();

    // Click the deepest item.
    [$dx, $dy, $dw, $dh] = $auto->backend()->contextSubmenuItemRect('ctxbtn', 0);
    $auto->backend()->injectPointer($dx + $dw / 2, $dy + $dh / 2, true, CM_LEFT);
    $auto->backend()->injectPointer($dx + $dw / 2, $dy + $dh / 2, false, CM_LEFT);
    $auto->step();

    expect($captured)->toContain('ctx:deepx');
    expect($auto->backend()->isContextMenuOpen('ctxbtn'))->toBeFalse();
});

test('menu items support an icon and a checked state', function () {
    $app = new App(
        init: fn() => [],
        update: fn(array $m, string $msg, mixed $p = null) => $m,
        view: fn() => Ui::window('Ctx', [
            Ui::contextMenu(Ui::button('Right', 'b:click', 'ctxbtn'), [
                ['title' => 'Bold',   'msg' => 'ctx:bold',   'icon' => '🔠', 'checked' => true],
                ['title' => 'Italic', 'msg' => 'ctx:italic', 'icon' => '𝑰',   'checked' => false],
                ['title' => 'Plain',  'msg' => 'ctx:plain'],
            ]),
        ], width: 300, height: 160),
    );
    $auto = (new Automation($app, new Canvas(headless: true)))->start();

    $w = Snapshot::findById($auto->snapshot(), 'ctxbtn');
    $cx = $w['x'] + $w['w'] / 2;
    $cy = $w['y'] + $w['h'] / 2;
    $auto->backend()->injectPointer($cx, $cy, true, CM_RIGHT);
    $auto->backend()->injectPointer($cx, $cy, false, CM_RIGHT);
    $auto->step();

    $items = $auto->backend()->contextMenuItems('ctxbtn');
    expect($items)->toHaveCount(3);
    expect($items[0])->toHaveKey('icon');
    expect($items[0]['icon'])->toBe('🔠');
    expect($items[0]['checked'])->toBeTrue();
    expect($items[1]['checked'])->toBeFalse();

    // The leading gutter widens the menu for icon/checked rows.
    [, , $mw] = $auto->backend()->contextMenuRect('ctxbtn');
    $maxLen = max(mb_strlen($items[0]['title']), mb_strlen($items[1]['title']), mb_strlen($items[2]['title']));
    expect($mw)->toBeGreaterThanOrEqual($maxLen * 7 + 24 + 22);

    // A checked row is still a normal command: clicking it dispatches.
    $captured = [];
    $tracker = new App(
        init: fn() => [],
        update: function (array $m, string $msg, mixed $p = null) use (&$captured) {
            $captured[] = $msg;
            return $m;
        },
        view: fn() => Ui::window('Ctx', [
            Ui::contextMenu(Ui::button('Right', 'b:click', 'ctxbtn'), [
                ['title' => 'Bold', 'msg' => 'ctx:bold', 'icon' => '🔠', 'checked' => true],
            ]),
        ], width: 300, height: 160),
    );
    $auto2 = (new Automation($tracker, new Canvas(headless: true)))->start();
    $w2 = Snapshot::findById($auto2->snapshot(), 'ctxbtn');
    $auto2->backend()->injectPointer($w2['x'] + $w2['w'] / 2, $w2['y'] + $w2['h'] / 2, true, CM_RIGHT);
    $auto2->backend()->injectPointer($w2['x'] + $w2['w'] / 2, $w2['y'] + $w2['h'] / 2, false, CM_RIGHT);
    $auto2->step();
    [$bx, $by, $bw, $bh] = $auto2->backend()->contextMenuItemRect('ctxbtn', 0);
    $auto2->backend()->injectPointer($bx + $bw / 2, $by + $bh / 2, true, CM_LEFT);
    $auto2->backend()->injectPointer($bx + $bw / 2, $by + $bh / 2, false, CM_LEFT);
    $auto2->step();
    expect($captured)->toContain('ctx:bold');
});
