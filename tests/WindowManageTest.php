<?php
declare(strict_types=1);

use Yangweijie\Ui3\{App, Ui, Element};
use Yangweijie\Ui3\Backends\Canvas;
use Yangweijie\Ui3\Automation\Automation;

/**
 * P-Native P1 — window management API (setTitle / resize / minimize / close).
 *
 * Headless: the native plat hooks are no-ops (host->plat is NULL), so these
 * tests verify the HOST STATE the API manipulates — title string, closed flag,
 * and width/height — which is the source of truth for a real window too.
 */
function windowApp(): App
{
    return new App(
        init: fn(): array => ['x' => ''],
        update: fn(array $m, string $msg, mixed $payload = null): array => $m,
        view: fn(array $m): Element => Ui::window('Win', [
            Ui::input('', 'a', 'a', 'a-input'),
        ], width: 320, height: 240),
    );
}

test('window title is set and reported back', function () {
    $auto = (new Automation(windowApp(), new Canvas(headless: true)))->start();
    $c = $auto->backend();

    expect($c->title())->toBe('Win');          // initial title comes from the view
    $c->setTitle('My Window');
    expect($c->title())->toBe('My Window');
});

test('window resize updates host width/height', function () {
    $auto = (new Automation(windowApp(), new Canvas(headless: true)))->start();
    $c = $auto->backend();

    expect($c->width())->toBe(320);
    expect($c->height())->toBe(240);
    $c->resize(500, 400);
    expect($c->width())->toBe(500);
    expect($c->height())->toBe(400);
});

test('window close flips the closed flag', function () {
    $auto = (new Automation(windowApp(), new Canvas(headless: true)))->start();
    $c = $auto->backend();

    expect($c->isClosed())->toBe(false);
    $c->close();
    expect($c->isClosed())->toBe(true);
});

test('App proxies delegate to the native window backend', function () {
    $app = windowApp();
    (new Automation($app, new Canvas(headless: true)))->start();

    $app->setTitle('Via App');
    expect($app->title())->toBe('Via App');
    $app->resize(640, 480);
    expect($app->backend()->width())->toBe(640);
    expect($app->isClosed())->toBe(false);
    $app->close();
    expect($app->isClosed())->toBe(true);
});
