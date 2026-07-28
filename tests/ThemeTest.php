<?php

declare(strict_types=1);

use Yangweijie\Ui3\App;
use Yangweijie\Ui3\Backends\Canvas;
use Yangweijie\Ui3\Element;
use Yangweijie\Ui3\Theme;
use Yangweijie\Ui3\Ui;

test('Theme ships light and dark token sets that diverge', function () {
    $light = Theme::get(Theme::LIGHT);
    $dark = Theme::get(Theme::DARK);
    expect($light['bg'])->not->toBe($dark['bg']);
    expect($light['text'])->not->toBe($dark['text']);
    // tokens carry geometry/typography too
    expect($light['radius'])->toBe(6);
    expect($light['fontSize'])->toBe(13);
});

test('Theme::register adds a bespoke theme', function () {
    Theme::register('sunset', ['bg' => [1, 0.9, 0.8]] + Theme::get(Theme::LIGHT));
    expect(Theme::names())->toContain('sunset');
    expect(Theme::get('sunset')['bg'])->toBe([1, 0.9, 0.8]);
});

test('Canvas resolves colors from the active theme', function () {
    $canvas = new Canvas(headless: true);
    $lightBg = $canvas->col('bg');

    $canvas->setTheme(Theme::DARK);
    $darkBg = $canvas->col('bg');
    expect($darkBg)->not->toBe($lightBg);
    expect($canvas->theme()['text'])->toBe(Theme::get(Theme::DARK)['text']);

    // unknown token falls back to black
    expect($canvas->col('nope'))->toBe([0.0, 0.0, 0.0]);
});

test('Ui::canvas builds a custom element carrying a callable draw', function () {
    $cb = fn() => null;
    $el = Ui::canvas($cb, 'my-canvas');
    expect($el->type)->toBe('custom');
    expect($el->prop('id'))->toBe('my-canvas');
    expect($el->prop('draw'))->toBe($cb);
});

test('custom draw callback is invoked on paint (headless step)', function () {
    $hit = null;
    $canvas = new Canvas(headless: true);
    $root = Ui::window('custom', [Ui::canvas(function ($cr, $x, $y, $w, $h) use (&$hit) {
        $hit = [$x, $y, $w, $h];
    })], 200, 120);
    $canvas->mount($root, fn() => null);
    $canvas->update($root); // computes layout so paint() has nodes
    $canvas->step();         // paints offscreen -> drawNode routes to the closure

    expect($hit)->not->toBeNull();
    [$x, $y, $w, $h] = $hit;
    expect($w)->toBeGreaterThan(0);
    expect($h)->toBeGreaterThan(0);
});

test('themed app exposes theme colors and renders a custom node without error', function () {
    $model = ['n' => 0];
    $update = fn($m, $msg) => $m;
    $view = fn($m) => Ui::window('themed', [
        Ui::label('hello'),
        Ui::canvas(fn() => null, 'c1'),
    ], 240, 160);

    $canvas = new Canvas(headless: true);
    $canvas->setTheme(Theme::DARK);
    $app = new App($model, $update, $view);
    $app->start();
    $canvas->mount($app->render(), fn(string $msg, mixed $payload = null) => $app->dispatch($msg, $payload));
    $canvas->update($app->render());
    $canvas->step();

    // theme applied to the backend's paint
    expect($canvas->col('text'))->toBe(Theme::get(Theme::DARK)['text']);

    // automation snapshot sees the custom node (no role, no closure leak)
    $auto = new \Yangweijie\Ui3\Automation\Automation($app, $canvas);
    $auto->start();
    $snap = $auto->snapshot();
    $custom = array_filter($snap['widgets'], fn($e) => ($e['role'] ?? '') === 'custom');
    expect($custom)->not->toBeEmpty();
});
