<?php
declare(strict_types=1);

use Yangweijie\Ui3\{App, Ui, Element};
use Yangweijie\Ui3\Backends\Canvas;
use Yangweijie\Ui3\Automation\{Automation, Snapshot};

function tabApp(): App
{
    return new App(
        init: fn(): array => ['a' => '', 'b' => '', 'c' => ''],
        update: function (array $m, string $msg, mixed $payload = null): array {
            return match ($msg) {
                'a' => [...$m, 'a' => (string) $payload],
                'b' => [...$m, 'b' => (string) $payload],
                'c' => [...$m, 'c' => (string) $payload],
                default => $m,
            };
        },
        view: function (array $m): Element {
            return Ui::window('Tab', [
                Ui::input($m['a'], 'a', 'a', 'a-input'),
                Ui::input($m['b'], 'b', 'b', 'b-input'),
                Ui::input($m['c'], 'c', 'c', 'c-input'),
                Ui::button('go', 'go', 'go-btn'),
            ], width: 300, height: 220);
        },
    );
}

/**
 * #4 — real window keyboard focus, part 2: Tab navigation + visible focus.
 * Tab cycles focus through the focusable widgets (the real window Tab-order
 * behavior), and the focused widget is drawn with an accent ring so the user
 * can see where keystrokes will land.
 */
test('Tab starts at the first focusable when nothing is focused', function () {
    $auto = (new Automation(tabApp(), new Canvas(headless: true)))->start();

    expect($auto->focusedId())->toBeNull();

    $auto->tab();

    expect($auto->focusedId())->toBe('a-input');
});

test('Tab advances focus through the widget order and wraps', function () {
    $auto = (new Automation(tabApp(), new Canvas(headless: true)))->start();

    expect($auto->focusedId())->toBeNull();
    $auto->tab();  expect($auto->focusedId())->toBe('a-input');
    $auto->tab();  expect($auto->focusedId())->toBe('b-input');
    $auto->tab();  expect($auto->focusedId())->toBe('c-input');
    $auto->tab();  expect($auto->focusedId())->toBe('go-btn');
    $auto->tab();  expect($auto->focusedId())->toBe('a-input'); // wrap
});

test('keystrokes after Tab target the Tab-focused field', function () {
    $auto = (new Automation(tabApp(), new Canvas(headless: true)))->start();

    $auto->tab(); // a-input
    $auto->tab(); // b-input
    $auto->type('y');

    expect($auto->model()['a'])->toBe('');
    expect($auto->model()['b'])->toBe('y'); // landed on the Tab-focused field
    expect($auto->model()['c'])->toBe('');
});

test('clicking a field then Tab moves to the next one', function () {
    $auto = (new Automation(tabApp(), new Canvas(headless: true)))->start();

    $b = Snapshot::findById($auto->snapshot(), 'b-input');
    $auto->clickAt($b['x'] + $b['w'] / 2, $b['y'] + $b['h'] / 2); // focus b on click
    expect($auto->focusedId())->toBe('b-input');

    $auto->tab(); // next after b is c
    expect($auto->focusedId())->toBe('c-input');
});
