<?php
declare(strict_types=1);

/**
 * Headless, pure-PHP animation demo.
 *
 * Drives the Reference renderer with the resident Ticker and writes one PNG
 * per frame — proving animation works WITHOUT the native libui3 host / FFI.
 *
 * Run:  php -f examples/animated_reference.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Yangweijie\Ui3\{Ui, Ticker};
use Yangweijie\Ui3\Backends\Reference;

$ref = new Reference(width: 360, height: 260);

$card = Ui::animate(
    Ui::card('Hello', [Ui::label('Fading in'), Ui::button('Go', 'go')], 'anim-card'),
    [
        ['key' => 'opacity', 'from' => 0.0, 'to' => 1.0, 'duration' => 1000, 'easing' => 'easeOut'],
        ['key' => 'y', 'from' => 40, 'to' => 0, 'duration' => 1000, 'easing' => 'linear'],
    ],
);

$ref->mount(Ui::window('Anim', [$card], 360, 260), fn() => null);

$dir = sys_get_temp_dir() . '/ui3_anim';
@mkdir($dir, 0775, true);

$ticker = new Ticker();
$frames = $ticker->run(function (float $t) use ($ref, $dir): void {
    $ref->setClock($t);
    $ref->savePng(sprintf('%s/frame_%04d.png', $dir, (int) round($t * 1000)));
}, 1.0, 10);

echo "wrote {$frames} frames to {$dir}\n";
