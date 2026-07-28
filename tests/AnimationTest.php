<?php

declare(strict_types=1);

use Yangweijie\Ui3\Animation;
use Yangweijie\Ui3\Backends\Canvas;
use Yangweijie\Ui3\Ui;

/**
 * Phase 4 (Direction 5): animation — ticker + transition/easing.
 *
 * Verifies the easing math, time-based interpolation, and that the canvas
 * backend advances a clock and applies translate / scale / opacity per frame.
 */
test('Animation easing curves behave correctly', function () {
    expect(Animation::ease('linear', 0.5))->toBe(0.5);
    // easeOut front-loads progress, easeIn back-loads it
    expect(Animation::ease('easeOut', 0.5))->toBeGreaterThan(0.5);
    expect(Animation::ease('easeIn', 0.5))->toBeLessThan(0.5);
    expect(Animation::ease('easeInOut', 0.5))->toBe(0.5);
    // easeOutBack overshoots past 1.0 near the end
    expect(Animation::ease('easeOutBack', 0.8))->toBeGreaterThan(1.0);
    // step jumps at the end
    expect(Animation::ease('step', 0.5))->toBe(0.0);
    expect(Animation::ease('step', 1.0))->toBe(1.0);
});

test('Animation progress clamps to [0,1]', function () {
    expect(Animation::progress(500, 1000))->toBe(0.5);
    expect(Animation::progress(-100, 1000))->toBe(0.0);   // before start
    expect(Animation::progress(2000, 1000))->toBe(1.0);    // after end
    expect(Animation::progress(200, 1000, 500))->toBe(0.0); // still in delay
    expect(Animation::progress(-1, 0))->toBe(1.0);         // zero duration
});

test('animated element interpolates opacity and translate across the clock', function () {
    $card = Ui::animate(
        Ui::card('Title', [Ui::label('hi')], 'anim-card'),
        [
            ['key' => 'opacity', 'from' => 0.0, 'to' => 1.0, 'duration' => 1000, 'easing' => 'easeOut'],
            ['key' => 'y', 'from' => 40, 'to' => 0, 'duration' => 1000, 'easing' => 'linear'],
        ],
    );

    $canvas = new Canvas(headless: true);
    $canvas->freezeClock();
    $root = Ui::window('anim', [$card], 240, 160);
    $canvas->mount($root, fn() => null);
    $canvas->update($root);

    $canvas->setTime(0.0);
    $canvas->requestRedraw();
    $canvas->step();
    $s0 = $canvas->animState('anim-card');
    expect($s0)->not->toBeNull();
    expect($s0['alpha'])->toBeLessThan(0.2);   // easeOut at t=0
    expect($s0['dy'])->toBe(40.0);             // translate 'from'
    expect($s0['done'])->toBeFalse();
    expect($canvas->isAnimating())->toBeTrue();

    $canvas->setTime(0.5);
    $canvas->requestRedraw();
    $canvas->step();
    $s5 = $canvas->animState('anim-card');
    expect($s5['alpha'])->toBeGreaterThan(0.5); // easeOut > linear at midpoint
    expect($s5['dy'])->toBe(20.0);              // linear 50%
    expect($s5['done'])->toBeFalse();

    $canvas->setTime(1.2);
    $canvas->requestRedraw();
    $canvas->step();
    $s12 = $canvas->animState('anim-card');
    expect($s12['alpha'])->toBeGreaterThan(0.99);
    expect($s12['dy'])->toBe(0.0);
    expect($s12['done'])->toBeTrue();
    expect($canvas->isAnimating())->toBeFalse();
});

test('animated scale interpolates element geometry', function () {
    $lbl = Ui::animate(
        Ui::label('grow', 'anim-scale'),
        [['key' => 'scale', 'from' => 0.5, 'to' => 1.0, 'duration' => 1000, 'easing' => 'linear']],
    );

    $canvas = new Canvas(headless: true);
    $canvas->freezeClock();
    $root = Ui::window('scale', [$lbl], 200, 120);
    $canvas->mount($root, fn() => null);
    $canvas->update($root);

    $canvas->setTime(0.0);
    $canvas->requestRedraw();
    $canvas->step();
    expect($canvas->animState('anim-scale')['scale'])->toBe(0.5);

    $canvas->setTime(1.0);
    $canvas->requestRedraw();
    $canvas->step();
    expect($canvas->animState('anim-scale')['scale'])->toBe(1.0);
});
