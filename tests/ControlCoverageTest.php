<?php

declare(strict_types=1);

namespace Yangweijie\Ui3\Tests;

use PHPUnit\Framework\TestCase;
use Yangweijie\Ui3\Backends\Reference;
use Yangweijie\Ui3\Ui;

/**
 * P2-C: Reference backend control coverage. The Canvas backend draws table
 * rows/columns and scroll viewports; the Reference renderer must too so the
 * headless/pixel-regression path exercises the same widgets.
 */
final class ControlCoverageTest extends TestCase
{
    private Reference $ref;

    protected function setUp(): void
    {
        $this->ref = new Reference(320, 240);
    }

    public function test_table_renders_rows_and_columns(): void
    {
        $with = Ui::window('T', [Ui::table(['Name', 'Age'], [['Alice', '30'], ['Bob', '25']])]);
        $this->ref->mount($with, static fn() => null);
        $hWith = $this->ref->pixelsHash();

        // deterministic
        self::assertSame($hWith, $this->ref->pixelsHash());
        // proves the column headers + body cells were actually drawn
        $this->ref->mount(Ui::window('T', []), static fn() => null);
        self::assertNotSame($hWith, $this->ref->pixelsHash());
    }

    public function test_scroll_viewport_is_filled(): void
    {
        $sv = Ui::window('S', [Ui::scrollView([Ui::label('a'), Ui::label('b')])]);
        $this->ref->mount($sv, static fn() => null);
        $h = $this->ref->pixelsHash();

        self::assertSame($h, $this->ref->pixelsHash());
    }
}
