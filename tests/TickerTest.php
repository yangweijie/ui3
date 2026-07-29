<?php

declare(strict_types=1);

use Yangweijie\Ui3\Animation;
use Yangweijie\Ui3\Backends\Reference;
use Yangweijie\Ui3\Ticker;

/**
 * P1: resident animation ticker (Ticker) + backend-agnostic interpolation
 * (Animation::frame) + CJK/IME-aware pure text measurement.
 */
test('Ticker drives frames up to a duration with an injectable clock', function (): void {
    $now = 0.0;
    $ticker = new Ticker(function () use (&$now): float {
        return $now;
    });
    $frames = [];
    $ticker->run(function (float $t) use (&$now, &$frames): void {
        $frames[] = $t;
        $now += 0.1; // 100ms per simulated frame
    }, 0.3);

    expect($frames)->toBe([0.0, 0.1, 0.2, 0.3]);
    expect($ticker->frames)->toBe(4);
});

test('Ticker stops early when a frame returns false', function (): void {
    $now = 0.0;
    $ticker = new Ticker(function () use (&$now): float {
        return $now;
    });
    $count = 0;
    $ticker->run(function (float $t) use (&$now, &$count) {
        $count++;
        $now += 0.1;
        return $count < 2 ? null : false;
    }, 10.0);

    expect($count)->toBe(2);
});

test('Animation::frame interpolates opacity/scale/translate and reports done', function (): void {
    $opacity = [['key' => 'opacity', 'from' => 0.0, 'to' => 1.0, 'duration' => 1000, 'easing' => 'linear']];
    expect(Animation::frame($opacity, 0.0)['alpha'])->toBe(0.0);
    expect(Animation::frame($opacity, 500.0)['alpha'])->toBe(0.5);
    $end = Animation::frame($opacity, 1000.0);
    expect($end['alpha'])->toBe(1.0);
    expect($end['done'])->toBeTrue();

    $mixed = [
        ['key' => 'scale', 'from' => 0.5, 'to' => 1.0, 'duration' => 1000],
        ['key' => 'y', 'from' => 10.0, 'to' => 0.0, 'duration' => 1000],
    ];
    $f = Animation::frame($mixed, 0.0);
    expect($f['scale'])->toBe(0.5);
    expect($f['dy'])->toBe(10.0);
    expect($f['dx'])->toBe(0.0);
    expect($f['alpha'])->toBe(1.0); // untouched keys default to 1.0
});

test('pure text width is CJK/IME aware (full-width vs half-width vs combining)', function (): void {
    // Half-width ASCII: ~0.6em per glyph
    expect(Reference::pureTextWidth('ABC', 10.0))->toBe(3 * 10.0 * 0.6);
    // Full-width CJK: ~1.0em
    expect(Reference::pureTextWidth('中', 10.0))->toBe(10.0);
    // Mixed: sum of the two classes
    expect(Reference::pureTextWidth('A中', 10.0))->toBe(10.0 * 0.6 + 10.0);
    // Precomposed é (single codepoint) is still half-width
    expect(Reference::pureTextWidth('é', 10.0))->toBe(10.0 * 0.6);
    // NFD "e" + combining acute: the combining mark adds 0 width (IME composition)
    $nfd = "e\xCC\x81";
    expect(Reference::pureTextWidth($nfd, 10.0))->toBe(10.0 * 0.6);
});
