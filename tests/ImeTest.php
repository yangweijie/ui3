<?php

declare(strict_types=1);

namespace Yangweijie\Ui3\Tests;

use PHPUnit\Framework\TestCase;
use Yangweijie\Ui3\App;
use Yangweijie\Ui3\Backends\Reference;
use Yangweijie\Ui3\Ui;

/**
 * P2-B: IME composition input. The Reference renderer shows a pending
 * composition as a preview (underlined) after the committed value; App::composition
 * injects it without a native event loop.
 */
final class ImeTest extends TestCase
{
    public function test_reference_renders_composition_preview(): void
    {
        $r = new Reference(320, 240);
        $r->mount(Ui::window('F', [Ui::input('', 'type', null, 'field-1')]), static fn() => null);
        $before = $r->pixelsHash();

        $r->composition('field-1', 'update', 'あい');
        $after = $r->pixelsHash();

        self::assertNotEquals($before, $after, 'IME composition preview must alter the rendered pixels');
    }

    public function test_composition_end_clears_preview(): void
    {
        $r = new Reference(320, 240);
        $r->mount(Ui::window('F', [Ui::input('', 'type', null, 'field-1')]), static fn() => null);
        $r->composition('field-1', 'update', 'あい');
        $withPreview = $r->pixelsHash();

        $r->composition('field-1', 'end', '');
        $cleared = $r->pixelsHash();

        self::assertNotEquals($withPreview, $cleared, 'composition end should remove the preview');
    }

    public function test_app_composition_injects_into_reference(): void
    {
        $backend = new Reference(320, 240);
        $app = new App(
            static fn() => null,
            static fn($m, $msg) => $m,
            static fn() => Ui::window('F', [Ui::input('', 'type', null, 'field-1')]),
        );
        $app->run($backend); // headless, stops after a single frame
        $h0 = $backend->pixelsHash();

        $app->composition('field-1', 'update', 'あい');
        $h1 = $backend->pixelsHash();

        self::assertNotEquals($h0, $h1, 'App::composition should repaint the headless backend with the preview');
    }
}
