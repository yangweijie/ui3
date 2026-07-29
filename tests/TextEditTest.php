<?php
declare(strict_types=1);

use Yangweijie\Ui3\Backends\Canvas;
use Yangweijie\Ui3\Automation\Automation;

/**
 * #5a — real text editing. type() drives the field through one real key event
 * per character; the backend holds a per-field edit buffer with a cursor, so
 * keystrokes insert at the cursor, backspace deletes, and the model receives
 * the running value — exactly like a native text control.
 */
test('type() builds the value character by character', function () {
    $auto = (new Automation(editApp(), new Canvas(headless: true)))->start();

    $auto->focus('v-input');
    $auto->type('he');
    expect($auto->model()['v'])->toBe('he');      // incremental, not one overwrite
    $auto->type('llo');
    expect($auto->model()['v'])->toBe('hello');
    expect($auto->fieldText('v-input'))->toBe('hello');
    expect($auto->fieldCursor('v-input'))->toBe(5); // cursor landed at the end
});

test('keystrokes insert at the cursor, not just append', function () {
    $auto = (new Automation(editApp(), new Canvas(headless: true)))->start();

    $auto->focus('v-input');
    $auto->type('hello');
    $auto->cursorLeft();
    $auto->cursorLeft();
    $auto->type('XY');

    expect($auto->fieldText('v-input'))->toBe('helXYlo'); // inserted at position 3
    expect($auto->fieldCursor('v-input'))->toBe(5);
    expect($auto->model()['v'])->toBe('helXYlo');
});

test('backspace deletes the character before the cursor', function () {
    $auto = (new Automation(editApp(), new Canvas(headless: true)))->start();

    $auto->focus('v-input');
    $auto->type('hello');
    $auto->cursorLeft();
    $auto->backspace(); // deletes the 'l' left of the cursor -> "helo"

    expect($auto->fieldText('v-input'))->toBe('helo');
    expect($auto->fieldCursor('v-input'))->toBe(3);
    expect($auto->model()['v'])->toBe('helo');
});

test('input() replaces the field content', function () {
    $auto = (new Automation(editApp(), new Canvas(headless: true)))->start();

    $auto->focus('v-input');
    $auto->type('abc');
    $auto->input('v-input', 'xyz'); // should overwrite, not append

    expect($auto->fieldText('v-input'))->toBe('xyz');
    expect($auto->model()['v'])->toBe('xyz');
});

/**
 * #1 — deterministic text editing. setValue() commits the whole value through
 * the app's onInput message, bypassing synthesized key events entirely. This is
 * the AI-friendly drive path (and the regression baseline for the Cocoa keyDown
 * delivery bug): it proves field text reaches the model without the OS key path.
 */
test('setValue() commits the whole value without key events', function () {
    $auto = (new Automation(editApp(), new Canvas(headless: true)))->start();

    $auto->setValue('v-input', 'hello');
    expect($auto->model()['v'])->toBe('hello');
    expect($auto->fieldText('v-input'))->toBe('hello');
    expect($auto->fieldCursor('v-input'))->toBe(5);
});

test('setValue() overwrites content and re-fires onInput', function () {
    $auto = (new Automation(editApp(), new Canvas(headless: true)))->start();

    $auto->focus('v-input');
    $auto->type('abc');
    $auto->setValue('v-input', 'replaced'); // overwrite, not append
    expect($auto->model()['v'])->toBe('replaced');
    expect($auto->fieldText('v-input'))->toBe('replaced');
});

test('setValue() rejects ids that are not text fields', function () {
    $auto = (new Automation(editApp(), new Canvas(headless: true)))->start();
    expect(fn() => $auto->setValue('nope', 'x'))->toThrow(\RuntimeException::class);
});

/**
 * Regression for the "input shows the placeholder instead of the typed value"
 * bug. The renderer used to read prop('value'), which input elements never set
 * (Ui::input stores the value under the 'text' prop), so it always fell back to
 * the placeholder — even though onInput had updated the model (labels bound to
 * the same field looked correct). fieldDisplayText() mirrors drawNode() and lets
 * us assert on the visible text without a pixel capture.
 */
test('field shows the typed value, not the placeholder', function () {
    $canvas = new Canvas(headless: true);
    $auto = (new Automation(editApp(), $canvas))->start();

    // empty field renders its placeholder
    expect($canvas->fieldDisplayText('v-input'))->toBe('value');

    // after editing, the field renders the value (placeholder must be gone)
    $auto->setValue('v-input', 'hello');
    expect($canvas->fieldDisplayText('v-input'))->toBe('hello');

    // and the same holds for the real key path, not just setValue()
    $auto->input('v-input', 'world');
    expect($canvas->fieldDisplayText('v-input'))->toBe('world');
    expect($auto->model()['v'])->toBe('world');
});

/**
 * P0.1 — selection by Shift+Arrow, then typing replaces the selection.
 * rawKey(123, true) carries the Shift modifier through ui3_key_text, which now
 * emits the "\x11" (KEY_SHIFT_LEFT) token so the editor can extend a selection.
 */
test('Shift+Arrow selects and typing replaces the selection', function () {
    $auto = (new Automation(editApp(), new Canvas(headless: true)))->start();
    $auto->focus('v-input');
    $auto->type('hello');                 // cursor at 5
    $auto->rawKey(123, true);             // Shift+Left -> selection [4,5]
    $auto->rawKey(123, true);             // Shift+Left -> selection [3,5]
    expect($auto->backend()->fieldSelectionRange('v-input'))->toBe([3, 5]);

    $auto->type('XY');                    // replaces "lo" -> "helXY"
    expect($auto->fieldText('v-input'))->toBe('helXY');
    expect($auto->fieldCursor('v-input'))->toBe(5);
});

test('Home and End move the cursor to the ends', function () {
    $auto = (new Automation(editApp(), new Canvas(headless: true)))->start();
    $auto->focus('v-input');
    $auto->type('hello');
    $auto->cursorLeft();
    $auto->cursorLeft();                  // cursor at 3
    $auto->rawKey(115);                   // Home
    expect($auto->fieldCursor('v-input'))->toBe(0);
    $auto->rawKey(119);                   // End
    expect($auto->fieldCursor('v-input'))->toBe(5);
});

test('Delete removes the character after the cursor', function () {
    $auto = (new Automation(editApp(), new Canvas(headless: true)))->start();
    $auto->focus('v-input');
    $auto->type('hello');
    $auto->rawKey(115);                   // Home -> cursor 0
    $auto->rawKey(117);                   // Delete -> "ello"
    expect($auto->fieldText('v-input'))->toBe('ello');
    expect($auto->fieldCursor('v-input'))->toBe(0);
});

test('Ctrl+Z undoes and Ctrl+Y redoes the last edit', function () {
    $auto = (new Automation(editApp(), new Canvas(headless: true)))->start();
    $auto->focus('v-input');
    $auto->type('hello');
    $auto->backspace();                   // deletes 'o' -> "hell"
    expect($auto->fieldText('v-input'))->toBe('hell');
    $auto->pressKey('Ctrl+z'); // undo -> "hello"
    expect($auto->fieldText('v-input'))->toBe('hello');
    $auto->pressKey('Ctrl+y'); // redo -> "hell"
    expect($auto->fieldText('v-input'))->toBe('hell');
});

test('Ctrl+A selects all, Ctrl+X cuts to clipboard, Ctrl+V pastes', function () {
    $auto = (new Automation(editApp(), new Canvas(headless: true)))->start();
    $auto->focus('v-input');
    $auto->type('hello');
    $auto->pressKey('Ctrl+a'); // select all
    expect($auto->backend()->fieldSelectionRange('v-input'))->toBe([0, 5]);
    $auto->pressKey('Ctrl+x'); // cut
    expect($auto->fieldText('v-input'))->toBe('');
    expect($auto->backend()->clipboard())->toBe('hello');
    $auto->pressKey('Ctrl+v'); // paste
    expect($auto->fieldText('v-input'))->toBe('hello');
});

test('Ctrl+C copies the selection without modifying the field', function () {
    $auto = (new Automation(editApp(), new Canvas(headless: true)))->start();
    $auto->focus('v-input');
    $auto->type('hello');
    $auto->pressKey('Ctrl+a');
    $auto->pressKey('Ctrl+c');
    expect($auto->backend()->clipboard())->toBe('hello');
    expect($auto->fieldText('v-input'))->toBe('hello'); // unchanged
});
