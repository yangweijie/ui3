<?php

declare(strict_types=1);

use Yangweijie\Ui3\Canvas\Layout;
use Yangweijie\Ui3\Ui;

test('grid flows children into columns and rows', function () {
    $g = Ui::grid([
        Ui::button('a', null, 'g1'),
        Ui::button('b', null, 'g2'),
        Ui::button('c', null, 'g3'),
        Ui::button('d', null, 'g4'),
    ], 2);
    $nodes = Layout::compute(Ui::window('grid-win', [$g], 320, 240));

    $byId = fn(string $id) => array_values(array_filter(
        $nodes,
        fn($n) => ($n->el->prop('id') ?? '') === $id
    ))[0] ?? null;

    $a = $byId('g1');
    $b = $byId('g2');
    $c = $byId('g3');
    $d = $byId('g4');

    expect($a)->not->toBeNull();
    expect($a->x)->toBe($c->x);            // same column
    expect($a->y)->toBe($b->y);            // same row
    expect($c->y)->toBeGreaterThan($a->y); // second row lower
    expect($b->x)->toBeGreaterThan($a->x); // second column right
});

test('positioned places a child at an absolute offset', function () {
    $abs = Ui::absolute([
        Ui::positioned(Ui::button('go', null, 'pos-btn'), 10, 20, 80, 30),
    ]);
    $nodes = Layout::compute(Ui::window('abs-win', [$abs], 320, 240));

    $btn = null;
    foreach ($nodes as $n) {
        if (($n->el->prop('id') ?? '') === 'pos-btn') {
            $btn = $n;
            break;
        }
    }
    expect($btn)->not->toBeNull();
    expect($btn->x)->toBe(12 + 10); // content pad + left
    expect($btn->y)->toBe(12 + 20); // content pad + top
    expect($btn->w)->toBe(80);
    expect($btn->h)->toBe(30);
});

test('grow distributes leftover vertical space to the growing child', function () {
    $col = Ui::column([
        Ui::label('top'),
        Ui::grow(Ui::spacer('sp')),
        Ui::button('bottom', null, 'bot'),
    ]);
    $nodes = Layout::compute(Ui::window('grow-win', [$col], 320, 240));

    $sp = null;
    foreach ($nodes as $n) {
        if (($n->el->prop('id') ?? '') === 'sp') {
            $sp = $n;
            break;
        }
    }
    expect($sp)->not->toBeNull();
    // content height 216; label 32 + button 30 + 2 pads (24) = 86 -> ~130 left
    expect($sp->h)->toBeGreaterThan(100);
});

test('virtual list materializes only the visible window', function () {
    $items = array_map(fn($i) => "item $i", range(1, 100));
    $list = Ui::with(Ui::list($items, -1, null, 'vl'), ['virtual' => true, 'scroll' => 10, 'viewport' => 10]);
    $nodes = Layout::compute(Ui::window('v-win', [$list], 320, 240));

    $rows = array_values(array_filter($nodes, fn($n) => $n->type === 'list_item'));
    expect(count($rows))->toBe(10);                 // only the viewport window
    expect(count($rows))->toBeLessThan(100);        // not the full 100
    expect($rows[0]->el->prop('_index'))->toBe(10); // scrolled to index 10
});
