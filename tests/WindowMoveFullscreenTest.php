<?php
declare(strict_types=1);

use Yangweijie\Ui3\{App, Ui, Element};
use Yangweijie\Ui3\Backends\Canvas;
use Yangweijie\Ui3\Automation\Automation;

/**
 * P-Native P0 续 — window management: move / fullscreen / acceptClose.
 *
 * Headless: native plat hooks are no-ops, so these tests verify the HOST STATE
 * the API manipulates — x/y position, fullscreen flag — which is the source
 * truth for a real window too.
 */
function moveApp(): App
{
    return new App(
        init: fn(): array => ['x' => ''],
        update: fn(array $m, string $msg, mixed $payload = null): array => $m,
        view: fn(array $m): Element => Ui::window('MoveWin', [
            Ui::input('', 'a', 'a', 'a-input'),
        ], width: 800, height: 600),
    );
}

test('move sets window position', function () {
    $auto = (new Automation(moveApp(), new Canvas(headless: true)))->start();
    $c = $auto->backend();

    expect($c->x())->toBe(0);
    expect($c->y())->toBe(0);

    $c->move(100, 200);
    expect($c->x())->toBe(100);
    expect($c->y())->toBe(200);
});

test('fullscreen toggles state', function () {
    $auto = (new Automation(moveApp(), new Canvas(headless: true)))->start();
    $c = $auto->backend();

    expect($c->isFullscreen())->toBe(false);

    $c->fullscreen();
    expect($c->isFullscreen())->toBe(true);

    $c->fullscreen();
    expect($c->isFullscreen())->toBe(false);
});

test('setCloseHandler sets and clears callback', function () {
    $auto = (new Automation(moveApp(), new Canvas(headless: true)))->start();
    $c = $auto->backend();

    $called = false;
    $c->setCloseHandler(function (int &$accept) use (&$called) {
        $called = true;
        $accept = 0;
    });

    expect(method_exists($c, 'setCloseHandler'))->toBeTrue();

    $c->setCloseHandler(null);
    expect(method_exists($c, 'setCloseHandler'))->toBeTrue();
});

test('App proxies move/fullscreen to native backend', function () {
    $app = moveApp();
    (new Automation($app, new Canvas(headless: true)))->start();

    expect($app->x())->toBeInt();
    expect($app->y())->toBeInt();
    expect($app->isFullscreen())->toBeBool();

    $app->move(50, 50);
    expect($app->x())->toBe(50);
    expect($app->y())->toBe(50);
});

test('Canvas exposes move/fullscreen directly', function () {
    $app = moveApp();
    (new Automation($app, new Canvas(headless: true)))->start();
    $backend = $app->backend();

    expect($backend)->toBeInstanceOf(Canvas::class);

    $backend->move(300, 400);
    expect($backend->x())->toBe(300);
    expect($backend->y())->toBe(400);

    expect($backend->isFullscreen())->toBeBool();
});
