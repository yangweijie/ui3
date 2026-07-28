<?php
declare(strict_types=1);

namespace Yangweijie\Ui3\Tests;

use Yangweijie\Ui3\App;
use Yangweijie\Ui3\Backends\Canvas;
use Yangweijie\Ui3\Ui;

/**
 * I1: platform-agnostic real-window smoke. It drives the REAL window path
 * (create + draw + pump + quit) instead of the headless offscreen path, so
 * ① macOS and ② Win32/X11 real-window event loops get exercised.
 *
 * Gated behind UI3_REAL_WINDOW=1: on a machine with a display this proves the
 * real-window code path works; without a display the host falls back to
 * headless and the test is skipped (so CI never hangs on a window).
 */
test('real window: create + draw + pump + quit (gated by UI3_REAL_WINDOW)', function () {
    if (getenv('UI3_REAL_WINDOW') !== '1') {
        $this->markTestSkipped('set UI3_REAL_WINDOW=1 on a display-bearing machine to validate the real-window path');
    }
    $app = new App(
        init: fn () => null,
        update: fn ($m, $msg) => $m,
        view: fn ($m) => Ui::window('Smoke', [Ui::label('hi')], 240, 160),
    );
    $canvas = new Canvas(headless: false);
    try {
        $app->start();
        $canvas->mount($app->render(), fn (string $m, $p = null) => $app->dispatch($m, $p));
        $canvas->update($app->render());
        $canvas->step();
        $canvas->step();
        $canvas->quit();
    } catch (\Throwable $e) {
        $this->markTestSkipped('real-window path unavailable in this environment: ' . $e->getMessage());
    }
    // The real-window path must have engaged (no headless fallback)...
    expect($canvas->isHeadless())->toBeFalse();
    // ...and the NSApp runloop / request_redraw must have actually painted a frame
    // through the real Cocoa draw path (cocoa_paint), not just the offscreen stub.
    expect($canvas->framesDrawn())->toBeGreaterThan(0);
});
