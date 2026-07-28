<?php
declare(strict_types=1);

use Yangweijie\Ui3\{App, Ui, Element};
use Yangweijie\Ui3\Backends\Canvas;
use Yangweijie\Ui3\Automation\Automation;

function kbApp(): App
{
    return new App(
        init: fn(): array => ['a' => '', 'sel' => -1, 'opt' => 0],
        update: function (array $m, string $msg, mixed $payload = null): array {
            return match ($msg) {
                'a' => [...$m, 'a' => (string) $payload],
                'list' => [...$m, 'sel' => (int) $payload],
                'select' => [...$m, 'opt' => (int) $payload],
                default => $m,
            };
        },
        view: function (array $m): Element {
            return Ui::window('Kb', [
                Ui::input($m['a'], 'a', 'a', 'a-input'),
                Ui::list(['one', 'two', 'three'], $m['sel'], 'list', 'my-list'),
                Ui::select(['x', 'y', 'z'], $m['opt'], 'select', 'my-select'),
                Ui::button('go', 'go', 'go-btn'),
            ], width: 300, height: 240);
        },
    );
}

/**
 * #5b — real-window keyboard accessibility, part 1: Shift+Tab reverse focus
 * traversal, and arrow-key + Enter browsing of list/select controls.
 */
test('Shift+Tab focuses the last widget when nothing is focused', function () {
    $auto = (new Automation(kbApp(), new Canvas(headless: true)))->start();

    expect($auto->focusedId())->toBeNull();
    $auto->shiftTab();
    expect($auto->focusedId())->toBe('go-btn');
});

test('Shift+Tab moves focus backward through the order and wraps', function () {
    $auto = (new Automation(kbApp(), new Canvas(headless: true)))->start();

    $auto->shiftTab(); expect($auto->focusedId())->toBe('go-btn');
    $auto->shiftTab(); expect($auto->focusedId())->toBe('my-select');
    $auto->shiftTab(); expect($auto->focusedId())->toBe('my-list');
    $auto->shiftTab(); expect($auto->focusedId())->toBe('a-input');
    $auto->shiftTab(); expect($auto->focusedId())->toBe('go-btn'); // wrap backward
});

test('list: arrows move highlight, Enter commits the selection', function () {
    $auto = (new Automation(kbApp(), new Canvas(headless: true)))->start();

    $auto->focus('my-list');
    expect($auto->highlightIndex('my-list'))->toBe(0); // seeded from value -1 -> 0

    $auto->arrowDown();
    expect($auto->highlightIndex('my-list'))->toBe(1);
    expect($auto->model()['sel'])->toBe(-1); // no commit on arrow (listbox)

    $auto->arrowDown();
    expect($auto->highlightIndex('my-list'))->toBe(2);

    $auto->enter();
    expect($auto->model()['sel'])->toBe(2); // Enter commits the highlight
});

test('select: arrows move and commit immediately, like a native dropdown', function () {
    $auto = (new Automation(kbApp(), new Canvas(headless: true)))->start();

    $auto->focus('my-select');
    expect($auto->highlightIndex('my-select'))->toBe(0); // seeded from value 0

    $auto->arrowDown();
    expect($auto->highlightIndex('my-select'))->toBe(1);
    expect($auto->model()['opt'])->toBe(1); // select commits on arrow

    $auto->arrowUp();
    expect($auto->highlightIndex('my-select'))->toBe(0);
    expect($auto->model()['opt'])->toBe(0);

    // Arrow up at the top stays at 0.
    $auto->arrowUp();
    expect($auto->highlightIndex('my-select'))->toBe(0);
});

test('arrow keys on a text field do not move list/select highlight', function () {
    $auto = (new Automation(kbApp(), new Canvas(headless: true)))->start();

    $auto->focus('a-input');
    $auto->arrowDown(); // text field ignores navigation keys
    expect($auto->model()['a'])->toBe('');
    expect($auto->focusedId())->toBe('a-input');
});
