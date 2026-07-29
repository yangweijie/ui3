<?php

declare(strict_types=1);

namespace Yangweijie\Ui3\Tests;

use PHPUnit\Framework\TestCase;
use Yangweijie\Ui3\App;
use Yangweijie\Ui3\Backends\Reference;
use Yangweijie\Ui3\Ui;

/**
 * P2-A: the App can drive a headless backend frame-by-frame with a Ticker,
 * advancing the animation clock and repainting — no native window needed.
 */
final class HeadlessLoopTest extends TestCase
{
    public function test_headless_loop_drives_animation_frames(): void
    {
        $hashes = [];
        $backend = new Reference(320, 240);

        $app = new App(
            static fn() => null,
            static fn($m, $msg) => $m,
            static fn() => Ui::window('Anim', [
                Ui::animate(
                    Ui::card('Hi', [Ui::label('hello')]),
                    [['key' => 'opacity', 'from' => 0, 'to' => 1, 'duration' => 1000, 'easing' => 'easeInOut']],
                ),
            ]),
        );

        $app->headless(frames: 16, fps: 10, durationSec: 1.5, onFrame: function (float $t, $b) use (&$hashes): void {
            $hashes[] = $b->pixelsHash();
        });
        $app->run($backend);

        self::assertCount(16, $hashes, 'should emit the requested number of frames');
        self::assertNotEquals($hashes[0], $hashes[10], 'pixels should change as opacity animates');
        self::assertFalse($backend->isAnimating(), 'animation should be done by the last frame');
    }

    public function test_headless_loop_stops_when_nothing_animates(): void
    {
        $count = 0;
        $backend = new Reference(200, 120);

        $app = new App(
            static fn() => null,
            static fn($m, $msg) => $m,
            static fn() => Ui::button('x'),
        );

        // No animation in the tree: without a frame cap the loop must stop
        // after the first frame rather than spin forever (capped at 30s by Ticker).
        $app->headless(frames: 100, fps: 60, onFrame: function () use (&$count): void {
            $count++;
        });
        $app->run($backend);

        self::assertSame(1, $count);
    }

    public function test_headless_clock_injection_is_deterministic(): void
    {
        $seen = [];
        $clock = 0.0;
        $backend = new Reference(160, 120);
        $app = new App(
            static fn() => null,
            static fn($m, $msg) => $m,
            static fn() => Ui::button('tick'),
        );
        $app->withClock(static function () use (&$clock): float {
            return $clock;
        });
        $app->headless(frames: 3, fps: 60, durationSec: 0.2, onFrame: function (float $t, $b) use (&$seen, &$clock): void {
            $seen[] = $b->clock();
            $clock += 0.1;
        });
        $app->run($backend);

        self::assertSame([0.0, 0.1, 0.2], $seen);
    }
}
