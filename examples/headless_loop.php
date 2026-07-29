<?php
declare(strict_types=1);
/**
 * P2-A demo: drive an animation through the headless app loop (no native window,
 * zero FFI). The Ticker advances a pure-PHP clock and the Reference backend
 * repaints each frame. Pass a directory as argv[1] to also dump PNG frames.
 */
require __DIR__ . '/../vendor/autoload.php';

use Yangweijie\Ui3\{App, Ui, Element};
use Yangweijie\Ui3\Backends\Reference;

$app = new App(
    init: static fn() => null,
    update: static fn($m, $msg) => $m,
    view: static function (): Element {
        return Ui::window('Headless Loop', [
            Ui::animate(
                Ui::card('Fade + slide', [Ui::label('Pure-PHP animation, no native window')]),
                [
                    ['key' => 'opacity', 'from' => 0, 'to' => 1, 'duration' => 800, 'easing' => 'easeOut'],
                    ['key' => 'y', 'from' => 20, 'to' => 0, 'duration' => 800, 'easing' => 'easeOut'],
                ],
            ),
        ]);
    },
);

$outDir = $argv[1] ?? null;
if ($outDir !== null && !is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}

$backend = new Reference(360, 200);
$hashes = [];
$app->headless(frames: 9, fps: 30, durationSec: 0.8, onFrame: function (float $t, $b) use (&$hashes, $outDir): void {
    $hashes[] = $b->pixelsHash();
    if ($outDir !== null) {
        $b->savePng(sprintf('%s/frame_%02d.png', $outDir, count($hashes)));
    }
});
$app->run($backend);

echo 'Rendered ' . count($hashes) . ' frames (headless, zero FFI)' . PHP_EOL;
echo 'Frames differ across time: ' . (count(array_unique($hashes)) > 1 ? 'yes' : 'no') . PHP_EOL;
if ($outDir !== null) {
    echo 'PNG frames written to: ' . $outDir . PHP_EOL;
}
