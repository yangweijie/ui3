<?php
declare(strict_types=1);

namespace Tests;

use Yangweijie\Ui3\{Ui, Element};
use Yangweijie\Ui3\Backends\Reference;

/**
 * P0: pixel-level regression via the pure-PHP Reference renderer.
 *
 * These tests run WITHOUT the native libui3 host / FFI, proving the layout and
 * draw logic are deterministic and catching unintended visual changes. Baseline
 * hashes live in tests/baselines/; regenerate with UI3_UPDATE_BASELINE=1.
 *
 * P1 adds headless animation: the same tree rendered at different clock values
 * must produce different pixels, so animation regressions are caught too.
 */
beforeEach(function (): void {
    $this->ref = new Reference(width: 360, height: 260);
});

it('renders deterministically (same tree -> same pixels)', function (): void {
    $view = basicView();
    $this->ref->mount($view(), fn() => null);
    expect($this->ref->pixelsHash())->toBe($this->ref->pixelsHash());
});

it('changes pixels when the model changes', function (): void {
    $off = new Reference(width: 360, height: 260);
    $on = new Reference(width: 360, height: 260);
    $off->mount(controlsView(false, 20.0)(), fn() => null);
    $on->mount(controlsView(true, 80.0)(), fn() => null);
    expect($off->pixelsHash())->not->toBe($on->pixelsHash());
});

it('matches baseline for a basic window', function (): void {
    $this->ref->mount(basicView()(), fn() => null);
    baseline($this->ref, 'basic_window', $this);
});

it('matches baseline for form controls', function (): void {
    $this->ref->mount(controlsView(true, 60.0)(), fn() => null);
    baseline($this->ref, 'controls', $this);
});

it('matches baseline for a list', function (): void {
    $this->ref->mount(listView()(), fn() => null);
    baseline($this->ref, 'list', $this);
});

// --- P1: headless animation ---------------------------------------------

it('animates across the clock: frame t=0 differs from t=0.6', function (): void {
    $this->ref->mount(animationView()(), fn() => null);
    $this->ref->setClock(0.0);
    $h0 = $this->ref->pixelsHash();
    $this->ref->setClock(0.6);
    $h06 = $this->ref->pixelsHash();
    expect($h0)->not->toBe($h06);
});

it('holds an in-flight animation then settles', function (): void {
    $this->ref->mount(animationView()(), fn() => null);
    $this->ref->setClock(0.0);
    $this->ref->pixelsHash(); // render fills animStates
    expect($this->ref->isAnimating())->toBeTrue();
    expect($this->ref->animState('anim-card'))->not->toBeNull();
    $this->ref->setClock(1.5);
    $this->ref->pixelsHash();
    expect($this->ref->isAnimating())->toBeFalse();
    $s = $this->ref->animState('anim-card');
    expect($s['done'])->toBeTrue();
    expect($s['alpha'])->toBeGreaterThan(0.99);
});

it('matches baseline for an animation frame at t=0', function (): void {
    $this->ref->mount(animationView()(), fn() => null);
    $this->ref->setClock(0.0);
    baseline($this->ref, 'anim_frame_t0', $this);
});

it('matches baseline for an animation frame at t=0.6', function (): void {
    $this->ref->mount(animationView()(), fn() => null);
    $this->ref->setClock(0.6);
    baseline($this->ref, 'anim_frame_t06', $this);
});

// --- fixtures -----------------------------------------------------------

function basicView(): \Closure
{
    return static fn(): Element => Ui::window('Demo', [
        Ui::heading('Hello'),
        Ui::label('A reference render'),
        Ui::button('Go', 'go'),
        Ui::input('', 'Email', 'email'),
    ], 360, 260);
}

function controlsView(bool $on, float $progress): \Closure
{
    return static fn(): Element => Ui::window('Controls', [
        Ui::checkbox('Agree', $on, 'cb'),
        Ui::slider(0, 100, $on ? 70 : 10, 'sl'),
        Ui::progress($progress),
        Ui::toggle('Wifi', $on, 'tg'),
    ], 360, 260);
}

function listView(): \Closure
{
    return static fn(): Element => Ui::window('List', [
        Ui::list([
            Ui::label('One'),
            Ui::label('Two'),
            Ui::label('Three'),
            Ui::label('Four'),
        ], 0, 'li'),
    ], 360, 260);
}

function animationView(): \Closure
{
    return static fn(): Element => Ui::window('Anim', [
        Ui::animate(
            Ui::card('Title', [Ui::label('hi')], 'anim-card'),
            [
                ['key' => 'opacity', 'from' => 0.0, 'to' => 1.0, 'duration' => 600, 'easing' => 'easeOut'],
                ['key' => 'y', 'from' => 40, 'to' => 0, 'duration' => 600, 'easing' => 'linear'],
            ],
        ),
    ], 360, 260);
}

// --- baseline helper -----------------------------------------------------

function baseline(Reference $ref, string $name, object $case): string
{
    $hash = $ref->pixelsHash();
    $dir = __DIR__ . '/baselines';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $path = $dir . '/' . $name . '.hash';
    if (!file_exists($path) || getenv('UI3_UPDATE_BASELINE')) {
        file_put_contents($path, $hash);
        $case->markTestSkipped("baseline {$name} created — rerun to assert pixel parity");
    }
    $expected = trim((string) file_get_contents($path));
    expect($hash)->toBe($expected, "pixel regression for {$name}");
    return $hash;
}
