<?php
declare(strict_types=1);

use Yangweijie\Ui3\Backends\Canvas;
use Yangweijie\Ui3\Automation\Automation;

test('setClipboard / clipboard round-trips via the in-memory mirror (headless)', function () {
    $canvas = new Canvas(headless: true);
    $canvas->setClipboard('hello world');
    expect($canvas->clipboard())->toBe('hello world');
});

test('App::setClipboard / clipboard delegates to the backend', function () {
    $app = editApp();
    $auto = (new Automation($app, new Canvas(headless: true)))->start();
    $app->setClipboard('via app');
    expect($app->clipboard())->toBe('via app');
});

test('copy stores the selected text on the clipboard', function () {
    $auto = (new Automation(editApp(), new Canvas(headless: true)))->start();
    $auto->focus('v-input');
    $auto->type('hello');
    $auto->pressKey('Ctrl+a');
    $auto->pressKey('Ctrl+c');
    expect($auto->backend()->clipboard())->toBe('hello');
});

test('cut removes the selection and stores it on the clipboard', function () {
    $auto = (new Automation(editApp(), new Canvas(headless: true)))->start();
    $auto->focus('v-input');
    $auto->type('hello');
    $auto->pressKey('Ctrl+a');
    $auto->pressKey('Ctrl+x');
    expect($auto->backend()->clipboard())->toBe('hello');
    expect($auto->backend()->fieldText('v-input'))->toBe('');
});

test('paste inserts the clipboard contents', function () {
    $auto = (new Automation(editApp(), new Canvas(headless: true)))->start();
    $auto->focus('v-input');
    $auto->backend()->setClipboard('pasted');
    $auto->pressKey('Ctrl+v');
    expect($auto->backend()->fieldText('v-input'))->toBe('pasted');
});

test('openFile / saveFile return null in headless (no native dialog reachable)', function () {
    $canvas = new Canvas(headless: true);
    expect($canvas->openFile())->toBeNull();
    expect($canvas->saveFile())->toBeNull();
});
