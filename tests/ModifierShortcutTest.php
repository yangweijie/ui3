<?php
declare(strict_types=1);

use Yangweijie\Ui3\Backends\Canvas;
use Yangweijie\Ui3\Automation\Automation;

/**
 * P-Native P0 — modifier keys (Alt/Cmd) and Cmd-shortcut capture.
 *
 * Headless automation drives the SAME native key path
 *   rawKey -> ui3_host_post_key -> push_inject -> onKey
 * so a Cmd+A / Ctrl+Alt+A proves the modifier mask is plumbed end-to-end:
 *   native ui3_key_text prefix  +  event `data` mask  +  PHP routing.
 *
 * Before this work, only Shift (and Ctrl-on-printable via a cocoa-only hack)
 * reached PHP, and Cmd+* fell through to the OS in performKeyEquivalent.
 */
test('modifier + Cmd shortcuts fire through the real key path', function () {
    $cmd = PHP_OS === 'Darwin' ? 8 : 2;   // Cmd on macOS, Ctrl elsewhere
    $auto = (new Automation(editApp(), new Canvas(headless: true)))->start();

    $auto->focus('v-input');
    $auto->input('v-input', 'Hello');

    $auto->rawKey(0, $cmd, 'a');          // Cmd+A / Ctrl+A -> select all
    $auto->rawKey(0, $cmd, 'x');          // Cmd+X / Ctrl+X -> cut
    expect($auto->fieldText('v-input'))->toBe('');

    $auto->rawKey(0, $cmd, 'v');          // Cmd+V / Ctrl+V -> paste
    expect($auto->fieldText('v-input'))->toBe('Hello');

    $auto->rawKey(0, $cmd, 'z');          // Cmd+Z / Ctrl+Z -> undo
    expect($auto->fieldText('v-input'))->toBe('');

    $auto->rawKey(0, $cmd | 1, 'z');      // Cmd+Shift+Z -> redo
    expect($auto->fieldText('v-input'))->toBe('Hello');
});

test('Alt and Cmd are plumbed into the key stream', function () {
    $auto = (new Automation(editApp(), new Canvas(headless: true)))->start();

    $auto->focus('v-input');
    $auto->input('v-input', 'Hello');

    // Ctrl+Alt+A must also select all (Alt is now carried, not dropped).
    $auto->rawKey(0, 2 | 4, 'a');
    $auto->rawKey(0, 2 | 4, 'x');
    expect($auto->fieldText('v-input'))->toBe('');

    // Alt alone must NOT insert the literal "Alt+k" label into the field.
    $auto->input('v-input', 'Hello');
    $auto->rawKey(0, 4, 'k');
    expect($auto->fieldText('v-input'))->toBe('Hello');
});
