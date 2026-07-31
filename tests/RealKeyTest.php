<?php
declare(strict_types=1);

use Yangweijie\Ui3\{App, Ui, Element};
use Yangweijie\Ui3\Backends\Canvas;
use Yangweijie\Ui3\Automation\Automation;

/** Mirror of the KeyboardNav fixture, scoped here to avoid a cross-file redeclaration. */
function realKeyApp(): App
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
 * #5c — real-window key integration. The native Cocoa keyDown now translates a
 * physical key into the SAME canonical text our onKey router expects; here we
 * feed keys by raw scancode through that exact mapping (ui3_key_text) so the
 * headless event path exercises the identical focus/Tab/text/browse routing a
 * live window would. macOS NSEvent.keyCode values:
 *   Tab 48, ArrowLeft 123, ArrowRight 124, ArrowUp 126, ArrowDown 125,
 *   Return 36, Backspace 51.
 */
test('raw Tab keycode 48 moves focus forward', function () {
    $auto = (new Automation(realKeyApp(), new Canvas(headless: true)))->start();

    $auto->rawKey(48);                 // Tab
    expect($auto->focusedId())->toBe('a-input');
    $auto->rawKey(48);
    expect($auto->focusedId())->toBe('my-list');
});

test('raw Shift+Tab (keycode 48, shift) moves focus backward', function () {
    $auto = (new Automation(realKeyApp(), new Canvas(headless: true)))->start();

    $auto->rawKey(48, 1);           // Shift+Tab
    expect($auto->focusedId())->toBe('go-btn');
    $auto->rawKey(48, 1);
    expect($auto->focusedId())->toBe('my-select');
});

test('raw ArrowDown + Return on a list commits via the native key path', function () {
    $auto = (new Automation(realKeyApp(), new Canvas(headless: true)))->start();

    $auto->focus('my-list');
    $auto->rawKey(125);                // ArrowDown
    $auto->rawKey(125);
    expect($auto->highlightIndex('my-list'))->toBe(2);
    $auto->rawKey(36);                 // Return
    expect($auto->model()['sel'])->toBe(2);
});

test('raw Backspace keycode 51 deletes the focused field char', function () {
    $auto = (new Automation(realKeyApp(), new Canvas(headless: true)))->start();

    $auto->focus('a-input');
    $auto->type('abc');
    expect($auto->fieldText('a-input'))->toBe('abc');
    $auto->rawKey(51);                 // Backspace
    expect($auto->fieldText('a-input'))->toBe('ab');
    expect($auto->model()['a'])->toBe('ab');
});

test('raw printable key (keycode 0, chars) inserts into the field', function () {
    $auto = (new Automation(realKeyApp(), new Canvas(headless: true)))->start();

    $auto->focus('a-input');
    $auto->rawKey(0, 0, 'z');
    expect($auto->fieldText('a-input'))->toBe('z');
});
