<?php
declare(strict_types=1);

use Yangweijie\Ui3\{App, Ui, Element};
use Yangweijie\Ui3\Backends\Canvas;
use Yangweijie\Ui3\Automation\{Automation, Snapshot};

function focusApp(): App
{
    return new App(
        init: fn(): array => ['a' => '', 'b' => ''],
        update: function (array $m, string $msg, mixed $payload = null): array {
            return match ($msg) {
                'a' => [...$m, 'a' => (string) $payload],
                'b' => [...$m, 'b' => (string) $payload],
                default => $m,
            };
        },
        view: function (array $m): Element {
            return Ui::window('Focus', [
                Ui::input($m['a'], 'field a', 'a', 'a-input'),
                Ui::input($m['b'], 'field b', 'b', 'b-input'),
                Ui::label("{$m['a']}|{$m['b']}", 'out'),
            ], width: 300, height: 200);
        },
    );
}

/**
 * #3 — real window keyboard focus. Keystrokes travel the real event path
 * (onEvent KEY) and are routed to whichever text field currently holds focus,
 * not dispatched blindly to the whole app. Focus is set by clicking a field
 * or by automation, exactly as a native window behaves.
 */
test('input() routes through the real focused-keyboard path', function () {
    $auto = (new Automation(focusApp(), new Canvas(headless: true)))->start();

    $auto->input('a-input', 'hello');

    expect($auto->model()['a'])->toBe('hello');
    expect($auto->model()['b'])->toBe(''); // untouched field stays empty
    expect(Snapshot::findById($auto->snapshot(), 'a-input')['state']['value'])->toBe('hello');
    expect(Snapshot::findById($auto->snapshot(), 'out')['name'])->toBe('hello|');
});

test('keystrokes target the focused field (focus routing)', function () {
    $auto = (new Automation(focusApp(), new Canvas(headless: true)))->start();

    $auto->focus('a-input');
    $auto->type('x');
    $auto->focus('b-input');
    $auto->type('y');

    expect($auto->model()['a'])->toBe('x'); // first keystroke went to a
    expect($auto->model()['b'])->toBe('y'); // second to b (focus moved)
});

test('keystroke with no focused field is a no-op', function () {
    $auto = (new Automation(focusApp(), new Canvas(headless: true)))->start();

    // Nothing focused yet — a real KEY event must not dispatch anywhere.
    $auto->type('zzz');

    expect($auto->model()['a'])->toBe('');
    expect($auto->model()['b'])->toBe('');
});

test('clicking an input focuses it for subsequent keystrokes', function () {
    $auto = (new Automation(focusApp(), new Canvas(headless: true)))->start();

    $a = Snapshot::findById($auto->snapshot(), 'a-input');
    $auto->clickAt($a['x'] + $a['w'] / 2, $a['y'] + $a['h'] / 2); // focus on click
    $auto->type('clicked');

    expect($auto->model()['a'])->toBe('clicked');
    expect($auto->model()['b'])->toBe('');
});
