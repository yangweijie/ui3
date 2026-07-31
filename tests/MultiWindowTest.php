<?php
declare(strict_types=1);

use Yangweijie\Ui3\{App, Ui, Element};
use Yangweijie\Ui3\Backends\Canvas;
use Yangweijie\Ui3\Automation\Automation;

/**
 * P-Native P0 续 — multi-window (真·OS 多 surface).
 *
 * Headless: extra hosts create offscreen surfaces, so these tests verify
 * the host lifecycle — create, count, destroy — without rendering.
 */

function mwApp(): App
{
    return new App(
        init: fn(): array => ['n' => 0],
        update: fn(array $m, string $msg, mixed $payload = null): array => $m,
        view: fn(array $m): Element => Ui::window('MainWin', [
            Ui::label('hello'),
        ], width: 640, height: 480),
    );
}

test('createExtraHost increments count', function () {
    $auto = (new Automation(mwApp(), new Canvas(headless: true)))->start();
    $c = $auto->backend();

    expect($c->extraHostCount())->toBe(0);

    $c->createExtraHost('win2', 'Second Window', 320, 240);
    expect($c->extraHostCount())->toBe(1);
});

test('createExtraHost deduplicates by id', function () {
    $auto = (new Automation(mwApp(), new Canvas(headless: true)))->start();
    $c = $auto->backend();

    $c->createExtraHost('win2', 'Second', 320, 240);
    $c->createExtraHost('win2', 'Second Again', 640, 480);
    expect($c->extraHostCount())->toBe(1);
});

test('destroyExtraHost decrements count', function () {
    $auto = (new Automation(mwApp(), new Canvas(headless: true)))->start();
    $c = $auto->backend();

    $c->createExtraHost('win2', 'Second', 320, 240);
    $c->createExtraHost('win3', 'Third', 320, 240);
    expect($c->extraHostCount())->toBe(2);

    $c->destroyExtraHost('win2');
    expect($c->extraHostCount())->toBe(1);

    $c->destroyExtraHost('win3');
    expect($c->extraHostCount())->toBe(0);
});

test('destroyExtraHost is safe for unknown id', function () {
    $auto = (new Automation(mwApp(), new Canvas(headless: true)))->start();
    $c = $auto->backend();

    // Should not throw
    $c->destroyExtraHost('nonexistent');
    expect($c->extraHostCount())->toBe(0);
});

test('App::openWindow creates real extra host', function () {
    $app = mwApp();
    $auto = (new Automation($app, new Canvas(headless: true)))->start();
    $c = $auto->backend();

    $app->openWindow('w2', 'Window 2', 400, 300);
    expect($c->extraHostCount())->toBe(1);
    expect($app->windows()->isOpen('w2'))->toBeTrue();
});

test('App::closeWindow destroys real extra host', function () {
    $app = mwApp();
    $auto = (new Automation($app, new Canvas(headless: true)))->start();
    $c = $auto->backend();

    $app->openWindow('w2', 'Window 2', 400, 300);
    expect($c->extraHostCount())->toBe(1);

    $app->closeWindow('w2');
    expect($c->extraHostCount())->toBe(0);
    expect($app->windows()->isOpen('w2'))->toBeFalse();
});

test('multiple extra hosts coexist', function () {
    $auto = (new Automation(mwApp(), new Canvas(headless: true)))->start();
    $c = $auto->backend();

    $c->createExtraHost('w2', 'Win 2', 320, 240);
    $c->createExtraHost('w3', 'Win 3', 640, 480);
    $c->createExtraHost('w4', 'Win 4', 800, 600);
    expect($c->extraHostCount())->toBe(3);

    $c->destroyExtraHost('w3');
    expect($c->extraHostCount())->toBe(2);
});

test('step handles main host plus extra hosts', function () {
    $auto = (new Automation(mwApp(), new Canvas(headless: true)))->start();
    $c = $auto->backend();

    $c->createExtraHost('w2', 'Win 2', 320, 240);
    // step() should not throw with multiple hosts
    $ret = $c->step();
    expect($ret)->toBeInt();
});

test('quit handles main host plus extra hosts', function () {
    $auto = (new Automation(mwApp(), new Canvas(headless: true)))->start();
    $c = $auto->backend();

    $c->createExtraHost('w2', 'Win 2', 320, 240);
    // quit() should not throw with multiple hosts
    $c->quit();
    expect(true)->toBeTrue();
});
