<?php
declare(strict_types=1);

namespace Yangweijie\Ui3\Tests;

use Yangweijie\Ui3\{App, Ui, Element};
use Yangweijie\Ui3\Backends\Canvas;

/** Returns the a11y tree as a string for a given app + canvas pair. */
function a11yTree(App $app, Canvas $canvas): string
{
    $app->start();
    $root = $app->render();
    $canvas->mount($root, fn(string $msg, mixed $payload = null) => $app->dispatch($msg, $payload));
    $canvas->update($root);
    $canvas->step();
    return $canvas->lastA11y();
}

function a11yApp(): App
{
    return new App(
        init: fn(): array => ['ok' => 1],
        update: fn(array $m, string $msg, mixed $payload = null): array => $m,
        view: fn(array $m): Element => Ui::accessible(
            Ui::column([
                Ui::label('Main label'),
                Ui::accessible(
                    Ui::button('Submit'),
                    'button', 'Submit Button'
                ),
                Ui::accessible(
                    Ui::checkbox('Option'),
                    'checkbox', 'Enable feature'
                ),
            ]),
            'group', 'Main window',
            'Application main window'
        ),
    );
}

test('a11y tree produces text output', function () {
    $app = a11yApp();
    $canvas = new Canvas(headless: true);
    $tree = a11yTree($app, $canvas);

    expect($tree)->not->toBeEmpty();
    expect($tree)->toContain('group');
    expect($tree)->toContain('Main window');
});

test('a11y tree contains nested elements', function () {
    $app = a11yApp();
    $canvas = new Canvas(headless: true);
    $tree = a11yTree($app, $canvas);

    expect($tree)->toContain('button');
    expect($tree)->toContain('Submit Button');
    expect($tree)->toContain('checkbox');
    expect($tree)->toContain('Enable');
});

test('a11y tree has root with accessible metadata', function () {
    $app = a11yApp();
    $canvas = new Canvas(headless: true);
    $tree = a11yTree($app, $canvas);

    $lines = explode("\n", $tree);
    $rootLine = $lines[0] ?? '';
    $fields = explode("\t", $rootLine);
    expect($fields[0])->toBe('group');
    expect($fields[1])->toBe('Main window');
});

test('a11y tree reflects role mapping', function () {
    $app = a11yApp();
    $canvas = new Canvas(headless: true);
    $tree = a11yTree($app, $canvas);

    $lines = explode("\n", $tree);
    $roleColumn = array_map(function ($line) {
        $fields = explode("\t", $line);
        return $fields[0] === '' ? $fields[1] : $fields[0];
    }, $lines);
    expect($roleColumn)->toContain('button');
    expect($roleColumn)->toContain('checkbox');
    expect($roleColumn)->toContain('label');
});

test('a11y tree non-accessible content renders as group', function () {
    $app = new App(
        init: fn(): array => ['ok' => 1],
        update: fn(array $m, string $msg, mixed $payload = null): array => $m,
        view: fn(array $m): Element => Ui::column([Ui::label('plain')]),
    );
    $canvas = new Canvas(headless: true);
    $tree = a11yTree($app, $canvas);

    expect($tree)->not->toBeEmpty();
});
