<?php
declare(strict_types=1);

namespace Yangweijie\Ui3\Tests;

use Yangweijie\Ui3\Compositor;

/**
 * Compositor unit tests — dirty rect tracking, merge logic, and
 * backing-surface lifecycle.
 *
 * These tests exercise the pure-PHP dirty-rect infrastructure. The
 * actual Cairo backing-surface does not need to be validated at the
 * pixel level here (that is covered by offscreenPixels assertions in
 * other tests like ControlsTest -> Canvas integration).
 */
function rects(Compositor $c): array { return $c->dirtyRects(); }

// ---- Dirty-rect basics ----

test('fresh compositor starts full dirty', function () {
    $c = new Compositor();
    $c->ensureSize(320, 240);
    expect(rects($c))->toBe([[0, 0, 320, 240]]);
});

test('markDirty after beginFrame resets', function () {
    $c = new Compositor();
    $c->ensureSize(320, 240);
    $c->beginFrame(1.0, 1.0, 1.0);
    $c->endFrame(tmpCr());
    // After endFrame, dirty rects should be empty (consumed)
    // but the next beginFrame will see fullDirty=false with empty rects → full clear
    expect(rects($c))->toBe([]);
});

test('markDirty accepts a small rect', function () {
    $c = new Compositor();
    $c->ensureSize(320, 240);
    $c->beginFrame(1.0, 1.0, 1.0);
    $c->endFrame(tmpCr()); // consume initial full-dirty

    $c->markDirty(10, 10, 50, 30);
    expect(rects($c))->toBe([[10, 10, 50, 30]]);
});

test('markDirty clips to surface bounds', function () {
    $c = new Compositor();
    $c->ensureSize(100, 100);
    $c->beginFrame(1.0, 1.0, 1.0);
    $c->endFrame(tmpCr());

    $c->markDirty(-10, -10, 200, 200); // extends beyond surface
    expect(rects($c))->toBe([[0, 0, 100, 100]]); // clipped
});

test('markDirty zero-size is ignored', function () {
    $c = new Compositor();
    $c->ensureSize(100, 100);
    $c->beginFrame(1.0, 1.0, 1.0);
    $c->endFrame(tmpCr());

    $c->markDirty(10, 10, 0, 0);
    expect(rects($c))->toBe([]);
});

// ---- Merge logic ----

test('overlapping rects merge into bounding box', function () {
    $c = new Compositor();
    $c->ensureSize(320, 240);
    $c->beginFrame(1.0, 1.0, 1.0);
    $c->endFrame(tmpCr());

    $c->markDirty(10, 10, 40, 40);
    $c->markDirty(30, 30, 40, 40); // overlaps
    expect(rects($c))->toHaveCount(1);
    // bounding box: min(10,30)=10, min(10,30)=10, max(50,70)-10=60, max(50,70)-10=60
    expect(rects($c)[0])->toBe([10, 10, 60, 60]);
});

test('non-overlapping rects stay separate', function () {
    $c = new Compositor();
    $c->ensureSize(320, 240);
    $c->beginFrame(1.0, 1.0, 1.0);
    $c->endFrame(tmpCr());

    $c->markDirty(10, 10, 20, 20);
    $c->markDirty(100, 100, 20, 20);
    expect(rects($c))->toHaveCount(2);
});

test('adjacent rects (touching) do not merge', function () {
    $c = new Compositor();
    $c->ensureSize(320, 240);
    $c->beginFrame(1.0, 1.0, 1.0);
    $c->endFrame(tmpCr());

    // Two rects side by side — they touch at x=30 but don't overlap
    $c->markDirty(10, 10, 20, 20);
    $c->markDirty(30, 10, 20, 20);
    expect(rects($c))->toHaveCount(2);
});

test('contained rect is merged into container', function () {
    $c = new Compositor();
    $c->ensureSize(320, 240);
    $c->beginFrame(1.0, 1.0, 1.0);
    $c->endFrame(tmpCr());

    $c->markDirty(10, 10, 100, 100);
    $c->markDirty(20, 20, 30, 30); // fully inside
    expect(rects($c))->toHaveCount(1);
    expect(rects($c)[0])->toBe([10, 10, 100, 100]); // unchanged
});

// ---- Full-dirty threshold ----

test('total dirty area over 50% triggers full redraw', function () {
    $c = new Compositor();
    $c->ensureSize(100, 100); // 10,000 px total
    $c->beginFrame(1.0, 1.0, 1.0);
    $c->endFrame(tmpCr());

    // 70 × 70 = 4,900 px (49%) — under threshold
    $c->markDirty(0, 0, 70, 70);
    expect($c->dirtyRects())->toHaveCount(1);

    // Adding another 30 × 30 = 900 px → total ≈ 5,800 px (58%) → triggers full
    $c->markDirty(70, 0, 30, 30);
    expect($c->dirtyRects())->toBe([[0, 0, 100, 100]]);
});

// ---- markFullDirty ----

test('markFullDirty resets to full surface', function () {
    $c = new Compositor();
    $c->ensureSize(320, 240);
    $c->beginFrame(1.0, 1.0, 1.0);
    $c->endFrame(tmpCr());

    $c->markDirty(10, 10, 20, 20);
    $c->markFullDirty();
    expect(rects($c))->toBe([[0, 0, 320, 240]]);
});

// ---- Frame lifecycle ----

test('beginFrame returns null before ensureSize', function () {
    $c = new Compositor();
    expect($c->beginFrame(1.0, 1.0, 1.0))->toBeNull();
});

test('beginFrame returns backing cr after ensureSize', function () {
    $c = new Compositor();
    $c->ensureSize(100, 100);
    $cr = $c->beginFrame(1.0, 1.0, 1.0);
    expect($cr)->not->toBeNull();
});

test('ensureSize recreates surface on dimension change', function () {
    $c = new Compositor();
    $c->ensureSize(100, 100);
    expect($c->width())->toBe(100);
    expect($c->height())->toBe(100);

    $c->ensureSize(200, 150);
    expect($c->width())->toBe(200);
    expect($c->height())->toBe(150);
});

test('readback returns correct dimensions', function () {
    $c = new Compositor();
    $c->ensureSize(80, 60);
    $rb = $c->readback();
    expect($rb['w'])->toBe(80);
    expect($rb['h'])->toBe(60);
    expect($rb['px'])->toHaveCount(60);
    expect($rb['px'][0])->toHaveCount(80);
});

// ---- Integration-style: Canvas integration (smoke) ----

test('canvas uses compositor for rendering', function () {
    // Quick smoke test: Canvas now has a Compositor and handles offscreenPixels
    $auto = new \Yangweijie\Ui3\Automation\Automation(
        canvasApp(),
        new \Yangweijie\Ui3\Backends\Canvas(headless: true)
    );
    $auto->start();
    $px = $auto->backend()->offscreenPixels();
    expect($px['w'])->toBeGreaterThan(0);
    expect($px['h'])->toBeGreaterThan(0);
    // px[0][0] should be the bg colour (theme bg, usually light)
    expect($px['px'][0])->toHaveCount($px['w']);
});

// Helpers

/** Minimal app with just a label, used for integration smoke-test. */
function canvasApp(): \Yangweijie\Ui3\App
{
    return new \Yangweijie\Ui3\App(
        init: fn (): array => [],
        update: fn (array $m, string $msg, mixed $p = null): array => $m,
        view: fn (array $m): \Yangweijie\Ui3\Element =>
            \Yangweijie\Ui3\Ui::window('Compositor', [
                \Yangweijie\Ui3\Ui::label('Hello', 'l1'),
            ], width: 160, height: 100),
    );
}

/**
 * Create a throw-away cairo_t for consuming compositor state in tests.
 * The surface is destroyed immediately; we only need the cr to satisfy
 * the endFrame signature so dirty rects are consumed properly.
 */
function tmpCr()
{
    $f = \Yangweijie\Ui3\FFI\Cairo::ffi();
    $surf = $f->cairo_image_surface_create(0, 1, 1);
    $cr = $f->cairo_create($surf);
    $f->cairo_surface_destroy($surf); // cr retains a reference
    return $cr;
}
