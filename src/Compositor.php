<?php
declare(strict_types=1);

namespace Yangweijie\Ui3;

use FFI;
use Yangweijie\Ui3\FFI\Cairo;

/**
 * Compositor with dirty-rect tracking and a persistent backing surface.
 *
 * Manages an offscreen ARGB32 image surface that accumulates the rendered
 * widget tree. On each frame only the dirty region is cleared and redrawn,
 * then blitted to the host cairo context — avoiding full-surface DMA.
 *
 * Dirty rects are merged on overlap to prevent redundant blits; if the
 * total area exceeds 50% of the surface a full redraw is triggered instead.
 *
 * Overlays (context menus, scrollbars) are drawn directly on the host cr
 * and are NOT part of the backing surface.
 */
final class Compositor
{
    private ?FFI\CData $backingSurface = null;
    private ?FFI\CData $backingCr = null;
    private int $w = 0;
    private int $h = 0;
    private bool $fullDirty = true;
    /** @var list<array{int,int,int,int}> [x, y, w, h] */
    private array $dirtyRects = [];

    public function __destruct()
    {
        $this->destroy();
    }

    // ---------------------------------------------------------------
    //  Surface management
    // ---------------------------------------------------------------

    /**
     * Ensure the backing surface matches the given dimensions.
     * Recreates the surface+context when the size changes.
     */
    public function ensureSize(int $w, int $h): void
    {
        if ($w === $this->w && $h === $this->h && $this->backingSurface !== null) {
            return;
        }
        $this->destroy();
        $f = Cairo::ffi();
        $this->backingSurface = $f->cairo_image_surface_create(0, $w, $h); // CAIRO_FORMAT_ARGB32
        $this->backingCr = $f->cairo_create($this->backingSurface);
        $this->w = $w;
        $this->h = $h;
        $this->fullDirty = true;
    }

    /** Current backing surface width. */
    public function width(): int
    {
        return $this->w;
    }

    /** Current backing surface height. */
    public function height(): int
    {
        return $this->h;
    }

    // ---------------------------------------------------------------
    //  Dirty-rect tracking
    // ---------------------------------------------------------------

    /**
     * Mark a rectangular region as needing redraw.
     * Coordinates are clipped to the surface bounds. Overlapping rects
     * are merged into their bounding box to prevent redundant blits.
     */
    public function markDirty(int $x, int $y, int $w, int $h): void
    {
        if ($this->fullDirty || $w <= 0 || $h <= 0) {
            return;
        }

        // Clip to surface bounds
        $x = max(0, $x);
        $y = max(0, $y);
        $w = min($w, $this->w - $x);
        $h = min($h, $this->h - $y);
        if ($w <= 0 || $h <= 0) {
            return;
        }

        // Try to merge with an existing overlapping rect
        $merged = false;
        foreach ($this->dirtyRects as $i => $r) {
            // Overlap test: one rect's left < other's right && one's top < other's bottom
            if ($x < $r[0] + $r[2] && $x + $w > $r[0] && $y < $r[1] + $r[3] && $y + $h > $r[1]) {
                $x1 = min($r[0], $x);
                $y1 = min($r[1], $y);
                $x2 = max($r[0] + $r[2], $x + $w);
                $y2 = max($r[1] + $r[3], $y + $h);
                $this->dirtyRects[$i] = [$x1, $y1, $x2 - $x1, $y2 - $y1];
                $merged = true;
                break;
            }
        }

        if (!$merged) {
            $this->dirtyRects[] = [$x, $y, $w, $h];
        }

        // If total dirty area exceeds 50%, switch to full redraw
        $totalArea = 0;
        foreach ($this->dirtyRects as $r) {
            $totalArea += $r[2] * $r[3];
        }
        if ($totalArea > $this->w * $this->h * 0.5) {
            $this->fullDirty = true;
            $this->dirtyRects = [];
        }
    }

    /** Mark the entire surface as dirty (full redraw). */
    public function markFullDirty(): void
    {
        $this->fullDirty = true;
        $this->dirtyRects = [];
    }

    /**
     * Return the current set of dirty rects.
     * When fullDirty is set, returns a single rect covering the whole surface.
     *
     * @return list<array{int,int,int,int}>
     */
    public function dirtyRects(): array
    {
        if ($this->fullDirty) {
            return [[0, 0, $this->w, $this->h]];
        }
        return $this->dirtyRects;
    }

    // ---------------------------------------------------------------
    //  Frame lifecycle
    // ---------------------------------------------------------------

    /**
     * Begin a new frame: clear dirty areas on the backing surface with
     * the given background colour and return the backing cairo context
     * for drawing.
     *
     * Caller MUST draw the complete widget tree onto the returned context
     * (or at least everything that intersects the dirty rects). Then call
     * endFrame() to blit the dirty region to the host.
     *
     * @return FFI\CData|null The backing cairo_t*, or null if not created yet.
     */
    public function beginFrame(float $bgR, float $bgG, float $bgB): ?FFI\CData
    {
        if ($this->backingCr === null) {
            return null;
        }

        if ($this->fullDirty || $this->dirtyRects === []) {
            // Full clear
            Cairo::fillRect($this->backingCr, 0, 0, $this->w, $this->h, $bgR, $bgG, $bgB);
            // Record the full rect so endFrame knows what to blit
            $this->dirtyRects = [[0, 0, $this->w, $this->h]];
            $this->fullDirty = false;
        } else {
            // Clear only dirty rects
            foreach ($this->dirtyRects as $r) {
                Cairo::fillRect($this->backingCr, $r[0], $r[1], $r[2], $r[3], $bgR, $bgG, $bgB);
            }
        }

        return $this->backingCr;
    }

    /**
     * End the frame: blit dirty rects from the backing surface to the
     * host cairo context. Overlays (menus, scrollbars) should be drawn
     * on the host cr AFTER this call so they sit on top.
     */
    public function endFrame($hostCr): void
    {
        if ($this->backingSurface === null) {
            return;
        }

        $f = Cairo::ffi();

        // Determine which rects to blit
        $rects = $this->fullDirty ? [[0, 0, $this->w, $this->h]] : $this->dirtyRects;
        if ($rects === []) {
            return; // nothing dirty → nothing to blit
        }

        foreach ($rects as $r) {
            // Notify cairo of the modified region on the backing surface
            $f->cairo_surface_mark_dirty_rectangle($this->backingSurface, $r[0], $r[1], $r[2], $r[3]);

            // Blit: set backing surface as source, clip to dirty rect, paint
            $f->cairo_set_source_surface($hostCr, $this->backingSurface, 0.0, 0.0);
            Cairo::save($hostCr);
            Cairo::clip($hostCr, (float)$r[0], (float)$r[1], (float)$r[2], (float)$r[3]);
            $f->cairo_paint($hostCr);
            Cairo::restore($hostCr);
        }

        // Reset dirty state for next frame
        $this->fullDirty = false;
        $this->dirtyRects = [];
    }

    // ---------------------------------------------------------------
    //  Readback (for tests / offscreenPixels)
    // ---------------------------------------------------------------

    /**
     * Read the current backing surface content as a pixel array,
     * matching the format of Canvas::offscreenPixels().
     *
     * @return array{w:int,h:int,px:array}
     */
    public function readback(): array
    {
        if ($this->backingSurface === null) {
            return ['w' => 0, 'h' => 0, 'px' => []];
        }

        $f = Cairo::ffi();
        $f->cairo_surface_flush($this->backingSurface);
        $data = $f->cairo_image_surface_get_data($this->backingSurface);
        $stride = $f->cairo_image_surface_get_stride($this->backingSurface);
        $px = [];
        for ($y = 0; $y < $this->h; $y++) {
            $row = [];
            for ($x = 0; $x < $this->w; $x++) {
                $o = $y * $stride + $x * 4;
                $row[] = [$data[$o + 2], $data[$o + 1], $data[$o]];
            }
            $px[] = $row;
        }
        return ['w' => $this->w, 'h' => $this->h, 'px' => $px];
    }

    // ---------------------------------------------------------------
    //  Internal
    // ---------------------------------------------------------------

    private function destroy(): void
    {
        $f = Cairo::ffi();
        if ($this->backingCr !== null) {
            $f->cairo_destroy($this->backingCr);
            $this->backingCr = null;
        }
        if ($this->backingSurface !== null) {
            $f->cairo_surface_destroy($this->backingSurface);
            $this->backingSurface = null;
        }
    }
}
