<?php
declare(strict_types=1);

use Yangweijie\Ui3\{App, Ui, Element};
use Yangweijie\Ui3\Backends\Canvas;
use Yangweijie\Ui3\Automation\Automation;

/**
 * P-Native P1 — native modal dialogs (alert / confirm / sheet / about).
 *
 * Headless: the native plat hooks are not invoked (host->plat is NULL), so
 * these tests verify (a) the HOST RECORDS the exact invocation passed down from
 * PHP (kind/style/title/message/buttons) and (b) the host returns the preset
 * dialog result — exercising the full PHP -> C -> preset -> PHP path without a
 * real OS window. The native rendering itself is implemented per platform but
 * cannot be asserted in CI.
 */
function dialogApp(): App
{
    return new App(
        init: fn(): array => ['x' => ''],
        update: fn(array $m, string $msg, mixed $payload = null): array => $m,
        view: fn(array $m): Element => Ui::window('Win', [
            Ui::input('', 'a', 'a', 'a-input'),
        ], width: 320, height: 240),
    );
}

test('alert records an info dialog with the given title/message', function () {
    $auto = (new Automation(dialogApp(), new Canvas(headless: true)))->start();
    $c = $auto->backend();

    $c->alert('Saved!', 'Title');

    $d = $c->lastDialog();
    expect($d)->not->toBeNull();
    expect($d['kind'])->toBe(0);     // info
    expect($d['style'])->toBe(0);    // window-modal
    expect($d['title'])->toBe('Title');
    expect($d['message'])->toBe('Saved!');
    expect($d['buttons'])->toBe('OK');
});

test('confirm returns true/false from the preset result and records a question', function () {
    $auto = (new Automation(dialogApp(), new Canvas(headless: true)))->start();
    $c = $auto->backend();

    $c->setDialogResult(0);          // OK clicked
    expect($c->confirm('Delete?', 'Confirm'))->toBeTrue();

    $d = $c->lastDialog();
    expect($d['kind'])->toBe(3);     // question
    expect($d['buttons'])->toBe('OK|Cancel');

    $c->setDialogResult(1);          // Cancel clicked
    expect($c->confirm('Delete?', 'Confirm'))->toBeFalse();
});

test('sheet records a sheet-style dialog', function () {
    $auto = (new Automation(dialogApp(), new Canvas(headless: true)))->start();
    $c = $auto->backend();

    $c->sheet('Pick a file', 'Sheet');

    $d = $c->lastDialog();
    expect($d['style'])->toBe(1);    // sheet
    expect($d['title'])->toBe('Sheet');
    expect($d['message'])->toBe('Pick a file');
});

test('about records an info dialog and dialog() returns the preset index', function () {
    $auto = (new Automation(dialogApp(), new Canvas(headless: true)))->start();
    $c = $auto->backend();

    $c->about('My App', 'Version 1.0');

    $d = $c->lastDialog();
    expect($d['kind'])->toBe(0);
    expect($d['title'])->toBe('My App');
    expect($d['message'])->toBe('Version 1.0');

    // Raw dialog() returns the exact preset button index.
    $c->setDialogResult(2);
    expect($c->dialog(2, 0, 'E', 'Boom', 'A|B|C'))->toBe(2);
});

test('App proxies delegate dialog calls to the native backend', function () {
    $app = dialogApp();
    (new Automation($app, new Canvas(headless: true)))->start();

    $app->setDialogResult(0);
    $app->alert('Hi', 'Via App');
    $d = $app->lastDialog();
    expect($d['title'])->toBe('Via App');
    expect($d['message'])->toBe('Hi');

    $app->setDialogResult(0);
    expect($app->confirm('OK?'))->toBeTrue();
});
