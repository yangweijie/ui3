<?php

declare(strict_types=1);

namespace Yangweijie\Ui3\Tests;

use PHPUnit\Framework\TestCase;
use Yangweijie\Ui3\Backends\Html;
use Yangweijie\Ui3\Ui;

/**
 * P2-D: Html (web target) backend animates. A tree with an `anim` spec emits
 * CSS @keyframes + an `animation` style, so the browser drives opacity/translate/
 * scale with no JS runtime and no FFI.
 */
final class HtmlAnimTest extends TestCase
{
    public function test_html_emits_animation_for_animated_nodes(): void
    {
        $html = new Html();
        $html->mount(
            Ui::window('A', [
                Ui::animate(Ui::card('Hi', []), [
                    ['key' => 'opacity', 'from' => 0, 'to' => 1, 'duration' => 1000, 'easing' => 'easeInOut'],
                ]),
            ]),
            static fn() => null,
        );
        $out = $html->html();

        self::assertStringContainsString('@keyframes ui3-a1', $out, 'should emit a keyframes block');
        self::assertStringContainsString('animation:ui3-a1 1000ms easeInOut', $out, 'animated node gets an animation style');
        self::assertStringContainsString('data-anim="1"', $out);
    }

    public function test_html_has_no_animation_without_spec(): void
    {
        $html = new Html();
        $html->mount(Ui::window('B', [Ui::card('Hi', [])]), static fn() => null);
        $out = $html->html();

        self::assertStringNotContainsString('@keyframes', $out);
        self::assertStringNotContainsString('animation:', $out);
    }

    public function test_html_animation_is_reproducible(): void
    {
        $html = new Html();
        $html->mount(
            Ui::window('A', [Ui::animate(Ui::card('Hi', []), [['key' => 'opacity', 'from' => 0, 'to' => 1]])]),
            static fn() => null,
        );
        self::assertSame($html->html(), $html->html(), 'animation output is deterministic');
    }
}
