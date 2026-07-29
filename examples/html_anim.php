<?php
declare(strict_types=1);
/**
 * P2-D demo: the Html backend turns an `anim` spec into pure CSS @keyframes +
 * an `animation` style — the browser drives opacity/translate/scale with no JS
 * runtime and no FFI. Prints the generated HTML to stdout.
 */
require __DIR__ . '/../vendor/autoload.php';

use Yangweijie\Ui3\Backends\Html;
use Yangweijie\Ui3\Ui;

$html = new Html();
$html->mount(
    Ui::window('Html Anim', [
        Ui::animate(
            Ui::card('Hello', [Ui::label('CSS-driven animation')]),
            [['key' => 'opacity', 'from' => 0, 'to' => 1, 'duration' => 600, 'easing' => 'easeInOut']],
        ),
    ]),
    static fn() => null,
);

echo $html->html() . PHP_EOL;
