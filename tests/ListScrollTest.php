<?php

declare(strict_types=1);

namespace Tests;

use Yangweijie\Ui3\App;
use Yangweijie\Ui3\Element;
use Yangweijie\Ui3\Canvas\Layout;
use Yangweijie\Ui3\Backends\Canvas;
use ReflectionMethod;

test('virtual list control draws an overlay scrollbar after interaction', function (): void {
    $root = new Element('window', ['width' => 320, 'height' => 240], [
        new Element('column', [], [
            new Element('label', ['text' => 'list']),
            new Element('list', [
                'id' => 'vl', 'virtual' => true, 'viewport' => 5, 'scroll' => 0,
                'items' => array_map(static fn(int $i): string => "Row #{$i}", range(0, 29)),
            ]),
        ]),
    ]);

    $backend = new Canvas(headless: true);
    $backend->mount($root, fn(): null => null);

    // Overlay scrollbar only appears after interaction (native behaviour).
    $backend->scrollBy('vl', 0);
    $buf = $backend->offscreenPixels();
    $px = $buf['px'];
    $thumb = $backend->col('scrollbarThumb');
    [$tr, $tg, $tb] = array_map(static fn(float $c): int => (int) ($c * 255), $thumb);

    $list = null;
    foreach (Layout::compute($root) as $n) {
        if ($n->type === 'list') {
            $list = $n;
            break;
        }
    }
    $tx = (int) ($list->x + $list->w - 8);
    $found = false;
    for ($y = (int) $list->y; $y < (int) ($list->y + $list->h); $y++) {
        $p = $px[$y][$tx];
        if (abs($p[0] - $tr) < 24 && abs($p[1] - $tg) < 24 && abs($p[2] - $tb) < 24) {
            $found = true;
            break;
        }
    }
    expect($found)->toBeTrue();
});

test('dragging the list scrollbar thumb scrolls the list', function (): void {
    $root = new Element('window', ['width' => 320, 'height' => 240], [
        new Element('column', [], [
            new Element('label', ['text' => 'list']),
            new Element('list', [
                'id' => 'vl', 'virtual' => true, 'viewport' => 5, 'scroll' => 0,
                'items' => array_map(static fn(int $i): string => "Row #{$i}", range(0, 29)),
            ]),
        ]),
    ]);

    $backend = new Canvas(headless: true);
    $backend->mount($root, fn(): null => null);

    $list = null;
    foreach (Layout::compute($root) as $n) {
        if ($n->type === 'list') {
            $list = $n;
            break;
        }
    }
    $contentH = Layout::scrollContentHeight('vl');
    $vh = (int) $list->h;
    $maxOff = max(0, $contentH - $vh);
    $thickness = 8;
    $trackX = $list->x + $list->w - $thickness - 2;
    $trackY = $list->y + 2;
    $trackH = $vh - 4;
    $thumbH = max(16, (int) (($vh / $contentH) * $trackH));
    $thumbY = $trackY; // offset 0 => thumb at the top

    $onEvent = new ReflectionMethod($backend, 'onEvent');
    $grabX = $trackX + $thickness / 2;
    $grabY = $thumbY + $thumbH / 2;

    // Pointer-down on the thumb grabs it but does not move the list yet.
    $onEvent->invoke($backend, 1, $grabX, $grabY, 0.0, null); // POINTER_DOWN
    expect($backend->scrollOffset('vl'))->toBe(0);

    // Drag the thumb to the middle of the track: offset becomes maxOff/2.
    $targetY = $trackY + $trackH / 2;
    $onEvent->invoke($backend, 3, $grabX, $targetY, 0.0, null); // POINTER_MOVE
    expect($backend->scrollOffset('vl'))->toBe((int) round($maxOff / 2));

    // Release.
    $onEvent->invoke($backend, 2, $grabX, $targetY, 0.0, null); // POINTER_UP
    expect($backend->scrollOffset('vl'))->toBe((int) round($maxOff / 2));
});

test('clicking the list scrollbar track jumps the list there', function (): void {
    $root = new Element('window', ['width' => 320, 'height' => 240], [
        new Element('column', [], [
            new Element('label', ['text' => 'list']),
            new Element('list', [
                'id' => 'vl', 'virtual' => true, 'viewport' => 5, 'scroll' => 0,
                'items' => array_map(static fn(int $i): string => "Row #{$i}", range(0, 29)),
            ]),
        ]),
    ]);

    $backend = new Canvas(headless: true);
    $backend->mount($root, fn(): null => null);

    $list = null;
    foreach (Layout::compute($root) as $n) {
        if ($n->type === 'list') {
            $list = $n;
            break;
        }
    }
    $contentH = Layout::scrollContentHeight('vl');
    $vh = (int) $list->h;
    $maxOff = max(0, $contentH - $vh);
    $thickness = 8;
    $trackX = $list->x + $list->w - $thickness - 2;
    $trackY = $list->y + 2;
    $trackH = $vh - 4;
    $thumbH = max(16, (int) (($vh / $contentH) * $trackH));
    $clickY = $trackY + $trackH * 0.8;

    $onEvent = new ReflectionMethod($backend, 'onEvent');
    $onEvent->invoke($backend, 1, $trackX + $thickness / 2, $clickY, 0.0, null); // POINTER_DOWN

    $jump = (int) ((($clickY - $trackY) * $maxOff) / max(1, $trackH - $thumbH));
    $expected = max(0, min($maxOff, $jump - (int) ($thumbH / 2)));
    expect($backend->scrollOffset('vl'))->toBe($expected);
});
