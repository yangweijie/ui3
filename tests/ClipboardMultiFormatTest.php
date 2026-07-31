<?php
declare(strict_types=1);

use Yangweijie\Ui3\{App, Element, Ui};
use Yangweijie\Ui3\Backends\Canvas;
use Yangweijie\Ui3\Automation\Automation;

function mftApp(): App
{
    return new App(
        init: fn(): array => ['x' => ''],
        update: fn(array $m, string $msg): array => $m,
        view: fn(array $m): Element => Ui::window('Win', [Ui::label('x')]),
    );
}

beforeEach(function () {
    $this->canvas = new Canvas(headless: true);
    $this->auto = (new Automation(mftApp(), $this->canvas))->start();
});

test('text clipboard round-trip', function () {
    $c = $this->auto->backend();
    $c->setClipboard('hello');
    expect($c->clipboard())->toBe('hello');
});

test('html clipboard round-trip', function () {
    $c = $this->auto->backend();
    $c->setClipboardHtml('<b>hi</b>');
    expect(strlen($c->getClipboardHtml()))->toBeGreaterThan(0);
});

test('uris clipboard round-trip', function () {
    $c = $this->auto->backend();
    $c->setClipboardUris('file:///a\nfile:///b');
    expect($c->getClipboardUris())->toContain('a');
});

test('clipboard formats returns text after text set', function () {
    $c = $this->auto->backend();
    $c->setClipboard('x');
    expect((bool)($c->clipboardFormats() & 1))->toBeTrue();
});

test('html formats flag after html set', function () {
    $c = $this->auto->backend();
    $c->setClipboardHtml('<div>hi</div>');
    expect((bool)($c->clipboardFormats() & 8))->toBeTrue();
});
