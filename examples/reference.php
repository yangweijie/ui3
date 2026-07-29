<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// 纯 PHP 参考渲染：无需原生 libui3 / FFI，把 Element 树栅格化为 PNG。
// 用于无头确定性渲染与像素级回归（tests/ReferenceRenderTest.php）。
//   php examples/reference.php
use Yangweijie\Ui3\{Ui, Element};
use Yangweijie\Ui3\Backends\Reference;

$view = static fn(): Element => Ui::window('Reference', [
    Ui::heading('Pixel parity'),
    Ui::label('Rendered without the native host'),
    Ui::button('Go', 'go'),
    Ui::progress(55.0),
], 360, 220);

$ref = new Reference(width: 360, height: 220);
$ref->mount($view(), fn() => null);

$out = sys_get_temp_dir() . '/ui3_reference.png';
$ref->savePng($out);
printf("rendered -> %s (%dx%d)  pixelsHash=%s\n", $out, 360, 220, $ref->pixelsHash());
echo "done (pure PHP, no FFI).\n";
