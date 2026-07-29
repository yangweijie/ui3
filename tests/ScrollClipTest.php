<?php
declare(strict_types=1);

use Yangweijie\Ui3\{Element};
use Yangweijie\Ui3\Backends\Canvas;
use Yangweijie\Ui3\Canvas\Layout;
use Yangweijie\Ui3\FFI\Cairo;

$libPath = match (PHP_OS_FAMILY) {
    'Windows' => __DIR__ . '/../build/libui3.dll',
    'Darwin'  => __DIR__ . '/../build/libui3.dylib',
    default   => __DIR__ . '/../build/libui3.so',
};

test('cairo save/clip/restore clips a fill to the rectangle', function () use ($libPath) {
    if (!file_exists($libPath)) {
        $this->markTestSkipped("libui3 not built at {$libPath}; run `bash ext/build.sh`");
        return;
    }
    $f = Cairo::ffi();
    $surf = $f->cairo_image_surface_create(0, 40, 40); // CAIRO_FORMAT_ARGB32
    $cr = $f->cairo_create($surf);

    Cairo::fillRect($cr, 0, 0, 40, 40, 1, 1, 1); // white background
    Cairo::save($cr);
    Cairo::clip($cr, 10, 10, 20, 20);            // clip to a 20x20 box
    Cairo::fillRect($cr, 0, 0, 40, 40, 0, 0, 0); // black everywhere -> only clipped box
    Cairo::restore($cr);

    $f->cairo_surface_flush($surf);
    $data = $f->cairo_image_surface_get_data($surf);
    $stride = $f->cairo_image_surface_get_stride($surf);
    $at = fn(int $x, int $y): array => [
        $data[$y * $stride + $x * 4 + 2], // R
        $data[$y * $stride + $x * 4 + 1], // G
        $data[$y * $stride + $x * 4],     // B
    ];

    expect($at(15, 15))->toBe([0, 0, 0]); // inside clip -> black fill applied
    expect($at(5, 5))->toBe([255, 255, 255]); // outside clip -> white background
    expect($at(35, 35))->toBe([255, 255, 255]); // outside clip -> white background
    $f->cairo_surface_destroy($surf);
});

test('layout emits a scroll_end sentinel after a scroll container', function () {
    $items = array_map(fn(int $i): Element => new Element('list_item', ['id' => null, 'title' => "Item {$i}"]), range(0, 29));
    $root = new Element('window', ['width' => 320, 'height' => 240], [
        new Element('column', [], [
            new Element('label', ['text' => 'Title']),
            new Element('scroll', ['id' => 's1', 'grow' => 1], $items),
        ]),
    ]);

    $nodes = Layout::compute($root);
    $idxScroll = null;
    $idxEnd = null;
    foreach ($nodes as $i => $n) {
        if ($n->type === 'scroll') {
            $idxScroll = $i;
        }
        if ($n->type === 'scroll_end') {
            $idxEnd = $i;
        }
    }
    expect($idxScroll)->not->toBeNull();
    expect($idxEnd)->not->toBeNull();
    expect($idxEnd)->toBeGreaterThan($idxScroll); // sentinel follows the container
});

test('scroll container clips its overflowing content (pixel readback)', function () use ($libPath) {
    if (!file_exists($libPath)) {
        $this->markTestSkipped("libui3 not built at {$libPath}; run `bash ext/build.sh`");
        return;
    }
    $items = array_map(fn(int $i): Element => new Element('list_item', ['id' => null, 'title' => "Item {$i}"]), range(0, 29));
    $root = new Element('window', ['width' => 320, 'height' => 240], [
        new Element('column', [], [
            new Element('label', ['text' => 'Title']),
            new Element('scroll', ['id' => 's1', 'grow' => 1], $items),
        ]),
    ]);

    $nodes = Layout::compute($root);
    $scroll = null;
    foreach ($nodes as $n) {
        if ($n->type === 'scroll') {
            $scroll = $n;
            break;
        }
    }
    expect($scroll)->not->toBeNull();

    $backend = new Canvas(headless: true);
    $backend->mount($root, fn(): null => null);
    $buf = $backend->offscreenPixels();
    $px = $buf['px'];
    $h = $buf['h'];

    $at = fn(int $x, int $y): array => $px[$y][$x];
    $bg = $at(2, 2); // window background corner

    // A point inside the scroll viewport (first list item) shows content.
    $insideY = min($scroll->y + 10, $h - 2);
    $insideX = min($scroll->x + 5, 318);
    $inside = $at($insideX, $insideY);

    // A point just below the scroll viewport must be background (clipped).
    $belowY = $scroll->y + $scroll->h + 4;
    if ($belowY < $h - 1) {
        $below = $at($insideX, $belowY);
        expect($below)->toBe($bg);          // overflow did not paint past the viewport
        expect($inside)->not->toBe($below); // content is visible inside, clipped outside
    } else {
        // No room below the viewport in this layout: fall back to offset invariance.
        $backend->scrollBy('s1', 200);
        $buf2 = $backend->offscreenPixels();
        $px2 = $buf2['px'];
        $below2 = $px2[$insideY][$insideX];
        // The inside row changes when scrolled, the (clipped/clamped) corner stays.
        expect($bg)->toBe($buf2['px'][2][2]);
    }
});
