<?php
declare(strict_types=1);

/**
 * Scroll-content clipping demo (native Cairo backend).
 *
 * The pure-PHP Reference backend just paints nodes without clipping, so an
 * overflowing scroll list would paint past its viewport. The Canvas backend
 * paints through Cairo, whose cairo_save / cairo_clip / cairo_restore let us
 * truly clip a scroll container's overflowing content to its viewport rect.
 *
 * This example renders a tall scrollable list offscreen (no display window),
 * proves the overflow is clipped by sampling pixels, scrolls, and re-renders
 * to show the viewport still clips while content moves. Two PNG frames are
 * exported when GD is available.
 *
 * Requirements: PHP FFI enabled (`-d ffi.enable=true`), libui3 built at
 * build/<lib>, and libcairo present on the system.
 *   php -d ffi.enable=true examples/scroll_clip.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Yangweijie\Ui3\{Element};
use Yangweijie\Ui3\Backends\Canvas;
use Yangweijie\Ui3\Canvas\Layout;

$libPath = match (PHP_OS_FAMILY) {
    'Windows' => __DIR__ . '/../build/libui3.dll',
    'Darwin'  => __DIR__ . '/../build/libui3.dylib',
    default   => __DIR__ . '/../build/libui3.so',
};

if (!extension_loaded('ffi')) {
    fwrite(STDERR, "FFI extension not loaded. Run with: php -d ffi.enable=true examples/scroll_clip.php\n");
    exit(1);
}
if (!file_exists($libPath)) {
    fwrite(STDERR, "libui3 not built at {$libPath}. Run `bash ext/build.sh` first.\n");
    exit(1);
}

// Build a window with a tall, scrollable list (content overflows the viewport).
$items = array_map(
    static fn(int $i): Element => new Element('list_item', ['title' => "Item #{$i}"]),
    range(0, 29),
);
$root = new Element('window', ['width' => 320, 'height' => 240], [
    new Element('column', [], [
        new Element('label', ['text' => 'Scroll list (overflow is clipped)']),
        new Element('scroll', ['id' => 'list', 'grow' => 1], $items),
    ]),
]);

$backend = new Canvas(headless: true);
$backend->mount($root, static fn(): null => null);

$scroll = null;
foreach (Layout::compute($root) as $n) {
    if ($n->type === 'scroll') {
        $scroll = $n;
        break;
    }
}

function probe(array $buf, $scroll, array $bg): array
{
    $px = $buf['px'];
    $at = static fn(int $x, int $y): array => $px[$y][$x];
    $insideX = min($scroll->x + 5, $buf['w'] - 2);
    $insideY = min($scroll->y + 10, $buf['h'] - 2);
    $belowY = $scroll->y + $scroll->h + 4;
    $below = $belowY < $buf['h'] - 1 ? $at($insideX, $belowY) : $bg;
    return [
        'inside'  => $at($insideX, $insideY),
        'below'   => $below,
        'clipped' => $below === $bg,
    ];
}

// Count RGB pixels that differ inside the viewport rect between two frames.
function viewportDiff(array $a, array $b, $scroll): int
{
    $n = 0;
    for ($y = (int) $scroll->y; $y < $scroll->y + $scroll->h; $y++) {
        for ($x = (int) $scroll->x; $x < $scroll->x + $scroll->w; $x++) {
            if ($a['px'][$y][$x] !== $b['px'][$y][$x]) {
                $n++;
            }
        }
    }
    return $n;
}

$bufTop = $backend->offscreenPixels();
$bg = $bufTop['px'][2][2];
$top = probe($bufTop, $scroll, $bg);

$backend->scrollBy('list', 120);
$bufScrolled = $backend->offscreenPixels();
$scrolled = probe($bufScrolled, $scroll, $bg);

$diff = viewportDiff($bufTop, $bufScrolled, $scroll);

printf("viewport content pixel : rgb(%d, %d, %d)  (painted over bg: %s)\n",
    $top['inside'][0], $top['inside'][1], $top['inside'][2],
    $top['inside'] !== $bg ? 'yes' : 'no');
printf("overflow region pixel  : rgb(%d, %d, %d)  (== bg => clipped: %s)\n",
    $top['below'][0], $top['below'][1], $top['below'][2], $top['clipped'] ? 'yes' : 'no');
printf("viewport pixels changed after scroll: %d  (content moved: %s)\n",
    $diff, $diff > 0 ? 'yes' : 'no');
printf("after scroll, overflow : rgb(%d, %d, %d)  (still clipped: %s)\n",
    $scrolled['below'][0], $scrolled['below'][1], $scrolled['below'][2],
    $scrolled['clipped'] ? 'yes' : 'no');

if (extension_loaded('gd') && function_exists('imagepng')) {
    $dir = sys_get_temp_dir() . '/ui3_scroll_clip';
    @mkdir($dir, 0777, true);
    dumpPng($bufTop, "{$dir}/frame_top.png");
    dumpPng($bufScrolled, "{$dir}/frame_scrolled.png");
    echo "PNG frames written to: {$dir}\n";
}

function dumpPng(array $buf, string $path): void
{
    $img = imagecreatetruecolor($buf['w'], $buf['h']);
    $cache = [];
    foreach ($buf['px'] as $y => $row) {
        foreach ($row as $x => [$r, $g, $b]) {
            $key = ($r << 16) | ($g << 8) | $b;
            $c = $cache[$key] ??= imagecolorallocate($img, $r, $g, $b);
            imagesetpixel($img, $x, $y, $c);
        }
    }
    imagepng($img, $path);
}
