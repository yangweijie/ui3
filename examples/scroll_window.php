<?php
declare(strict_types=1);

/**
 * Real-window scroll demo (Canvas backend, native Cairo).
 *
 * Shows a tall scrollable list in a real Cocoa/Win32/X11 window. The list
 * overflows its viewport, and the Canvas backend clips the overflow to the
 * viewport rect (same clipping as examples/scroll_clip.php, but live).
 *
 * Scrolling:
 *   - Mouse wheel over the list scrolls it (needs the libui3 wheel hook).
 *   - Or click the list, then press the Up / Down arrow keys.
 *
 * Run:
 *   bash bin/run.sh php -d ffi.enable=true examples/scroll_window.php                   # headless sanity frame
 *   UI3_REAL_WINDOW=1 bash bin/run.sh php -d ffi.enable=true examples/scroll_window.php # real window
 */

require __DIR__ . '/../vendor/autoload.php';

use Yangweijie\Ui3\{App, Element};

$items = array_map(
    static fn(int $i): Element => new Element('list_item', ['title' => "Item #{$i}"]),
    range(0, 29),
);

$app = new App(
    init: static fn(): array => ['items' => $items],
    update: static fn(array $model, string $msg, mixed $payload = null): array => $model,
    view: static function (array $model): Element {
        return new Element('window', ['width' => 320, 'height' => 240], [
            new Element('column', [], [
                new Element('label', ['text' => 'Scroll list — overflow is clipped']),
                new Element('scroll', ['id' => 'list', 'grow' => 1], $model['items']),
            ]),
        ]);
    },
);

if (getenv('UI3_REAL_WINDOW')) {
    // Blocks in a native event loop until the window is closed.
    $app->run();
} else {
    $backend = new \Yangweijie\Ui3\Backends\Canvas(headless: true);
    $app->start();
    $backend->mount($app->render(), fn(string $msg, $p = null) => $app->dispatch($msg, $p));
    $backend->step();
    $backend->quit();
    printf("headless sanity frame ok (%d frames painted)\n", $backend->framesDrawn());
}
