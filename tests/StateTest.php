<?php

declare(strict_types=1);

use Yangweijie\Ui3\Backends\Canvas;
use Yangweijie\Ui3\Element;
use Yangweijie\Ui3\Reconcile;
use Yangweijie\Ui3\Signal;
use Yangweijie\Ui3\Ui;

/**
 * Phase 6 (Direction 4): state / responsive.
 *
 * Covers a signals-lite primitive, keyed list reconciliation (native's
 * canvas_widget_reconcile diff), a responsive breakpoint helper, and that
 * list items keep a stable node id when given a `key`.
 */
test('Signal notifies subscribers on change and supports update', function () {
    $s = new Signal(0);
    $seen = [];
    $s->subscribe(static function ($v) use (&$seen) {
        $seen[] = $v;
    });
    $s->set(1);
    $s->set(1); // no-op: identical value does not notify
    $s->update(static fn($v) => $v + 4);
    expect($s->get())->toBe(5);
    expect($seen)->toBe([1, 5]);
});

test('Reconcile matches list items by stable key across a reorder', function () {
    $prev = [
        new Element('row', ['key' => 'a']),
        new Element('row', ['key' => 'b']),
        new Element('row', ['key' => 'c']),
    ];
    $next = [
        new Element('row', ['key' => 'b']),
        new Element('row', ['key' => 'c']),
        new Element('row', ['key' => 'a']),
        new Element('row', ['key' => 'd']),
    ];
    $diff = Reconcile::keyed($prev, $next);

    $byKey = [];
    foreach ($diff as $e) {
        $byKey[$e['key']] = $e['status'];
    }
    expect($byKey)->toBe([
        'b' => 'moved',
        'c' => 'moved',
        'a' => 'moved',
        'd' => 'added',
    ]);

    // 'b' existed before and after → not added/removed
    $b = null;
    foreach ($diff as $e) {
        if ($e['key'] === 'b') {
            $b = $e;
        }
    }
    expect($b['prev'])->not->toBeNull();
    expect($b['next'])->not->toBeNull();
});

test('Reconcile reports removals', function () {
    $prev = [new Element('row', ['key' => 'x']), new Element('row', ['key' => 'y'])];
    $next = [new Element('row', ['key' => 'x'])];
    $diff = Reconcile::keyed($prev, $next);
    $removed = array_filter($diff, static fn($e) => $e['status'] === 'removed');
    expect($removed)->toHaveCount(1);
    expect(array_values($removed)[0]['key'])->toBe('y');
});

test('breakpoint classifies width into sm/md/lg', function () {
    expect(Ui::breakpoint(320))->toBe('sm');
    expect(Ui::breakpoint(600))->toBe('md');
    expect(Ui::breakpoint(1200))->toBe('lg');
});

test('list items keep a stable node id from their key', function () {
    $list = Ui::list(
        [new Element('row', ['key' => 'k1', 'title' => 'One']), new Element('row', ['key' => 'k2', 'title' => 'Two'])],
        -1,
        'lst:select',
        'lst',
    );
    $canvas = new Canvas(headless: true);
    $root = Ui::window('keys', [$list], 200, 200);
    $canvas->mount($root, fn() => null);
    $canvas->update($root);
    $canvas->requestRedraw();
    $canvas->step();

    $ids = [];
    foreach ($canvas->layout() as $n) {
        if ($n->type === 'list_item') {
            $ids[] = $n->el->prop('id');
        }
    }
    expect($ids)->toBe(['k1', 'k2']);
});
