<?php
declare(strict_types=1);

use Yangweijie\Ui3\{App, Ui, Element};
use Yangweijie\Ui3\Backends\Canvas;
use Yangweijie\Ui3\Automation\Automation;

/**
 * P-Native P1 — drag & drop (files / text).
 *
 * Headless: the native plat path is not exercised; instead a DROP event is
 * injected through the SAME ui3_host_inject_drop -> event_cb -> onDrop path a
 * native drag would use, so these tests verify the full event -> window
 * onDrop -> App update wiring. Native delivery (Cocoa performDragOperation /
 * Win32 WM_DROPFILES) is implemented per platform; X11 XDND is a documented
 * gap (raw-X11 backend, no GtkWidget).
 */
function dndApp(): App
{
    return new App(
        init: fn(): array => ['dropped' => null],
        update: function (array $m, string $msg, mixed $payload = null): array {
            if ($msg === 'drop' && is_array($payload)) {
                $m['dropped'] = $payload;
            }
            return $m;
        },
        view: fn(array $m): Element => Ui::window('Drop', [
            Ui::label('drop here'),
        ], width: 320, height: 240, onDrop: 'drop'),
    );
}

test('dropping files delivers a files payload to the window onDrop handler', function () {
    $auto = (new Automation(dndApp(), new Canvas(headless: true)))->start();

    $auto->drop("/tmp/a.txt\n/tmp/b.png", type: 1, x: 10.0, y: 20.0);

    $m = $auto->model();
    expect($m['dropped'])->not->toBeNull()
        ->and($m['dropped']['type'])->toBe('files')
        ->and($m['dropped']['text'])->toBe("/tmp/a.txt\n/tmp/b.png")
        ->and($m['dropped']['x'])->toBe(10.0)
        ->and($m['dropped']['y'])->toBe(20.0);
});

test('dropping text delivers a text payload', function () {
    $auto = (new Automation(dndApp(), new Canvas(headless: true)))->start();

    $auto->drop('hello world', type: 0);

    $m = $auto->model();
    expect($m['dropped']['type'])->toBe('text')
        ->and($m['dropped']['text'])->toBe('hello world');
});

test('a window without onDrop ignores drops silently', function () {
    $app = new App(
        init: fn(): array => ['dropped' => null],
        update: fn(array $m, string $msg, mixed $payload = null): array => $m,
        view: fn(array $m): Element => Ui::window('NoDrop', [Ui::label('x')]),
    );
    $auto = (new Automation($app, new Canvas(headless: true)))->start();

    $auto->drop('/tmp/a.txt', type: 1);

    $m = $auto->model();
    expect($m['dropped'])->toBeNull();   // no handler -> no crash, no change
});
