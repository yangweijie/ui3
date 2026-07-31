<?php
declare(strict_types=1);

use Yangweijie\Ui3\{App, Ui, Element};
use Yangweijie\Ui3\Backends\Canvas;
use Yangweijie\Ui3\Automation\Automation;

/**
 * P-Native P1 — native notification / toast.
 *
 * Headless: the native plat hook is not invoked, so these tests verify the
 * full PHP -> C -> recorded path: notify() returns success and lastNotify()
 * echoes exactly what was passed. The native delivery itself (Cocoa
 * NSUserNotificationCenter / notify-send / WinRT toast) cannot be asserted in
 * CI.
 */
function notifyApp(): App
{
    return new App(
        init: fn(): array => ['x' => ''],
        update: fn(array $m, string $msg, mixed $payload = null): array => $m,
        view: fn(array $m): Element => Ui::window('Win', [
            Ui::input('', 'a', 'a', 'a-input'),
        ], width: 320, height: 240),
    );
}

test('notify returns success and records title/body headless', function () {
    $auto = (new Automation(notifyApp(), new Canvas(headless: true)))->start();
    $c = $auto->backend();

    expect($c->notify('Build done', 'All 165 tests passed'))->toBeTrue();

    $n = $c->lastNotify();
    expect($n)->not->toBeNull();
    expect($n['title'])->toBe('Build done');
    expect($n['body'])->toBe('All 165 tests passed');
});

test('notify with empty body records an empty body', function () {
    $auto = (new Automation(notifyApp(), new Canvas(headless: true)))->start();
    $c = $auto->backend();

    expect($c->notify('Heads up'))->toBeTrue();

    $n = $c->lastNotify();
    expect($n['title'])->toBe('Heads up');
    expect($n['body'])->toBe('');
});

test('App proxies delegate notify to the native backend', function () {
    $app = notifyApp();
    (new Automation($app, new Canvas(headless: true)))->start();

    expect($app->notify('Via App', 'ok'))->toBeTrue();
    $n = $app->lastNotify();
    expect($n['title'])->toBe('Via App');
    expect($n['body'])->toBe('ok');
});
