<?php
declare(strict_types=1);
/**
 * P2-B demo: feed an IME composition event to the headless Reference backend and
 * render the candidate preview (accent text + underline). Pass argv[1] as an
 * output PNG path to also save the preview image.
 */
require __DIR__ . '/../vendor/autoload.php';

use Yangweijie\Ui3\Backends\Reference;
use Yangweijie\Ui3\Ui;

$ref = new Reference(360, 120);
$ref->mount(Ui::window('IME', [Ui::input('', 'Type here', null, 'field-1')]), static fn() => null);

$before = $ref->pixelsHash();
$ref->composition('field-1', 'update', 'あい');
$after = $ref->pixelsHash();
$ref->composition('field-1', 'end', '');
$cleared = $ref->pixelsHash();

echo 'Composition preview changes pixels: ' . ($before !== $after ? 'yes' : 'no') . PHP_EOL;
echo 'Composition end clears preview: ' . ($after !== $cleared ? 'yes' : 'no') . PHP_EOL;

$out = $argv[1] ?? null;
if ($out !== null) {
    $ref->composition('field-1', 'update', 'か');
    $ref->savePng($out);
    echo 'Saved preview to: ' . $out . PHP_EOL;
}
