<?php
declare(strict_types=1);

use Yangweijie\Ui3\{App, Ui, Element};
use Yangweijie\Ui3\Backends\Canvas;
use Yangweijie\Ui3\Automation\{Automation, Script, Snapshot};

function autoApp(): App
{
    return new App(
        init: fn(): array => ['count' => 0],
        update: function (array $model, string $msg): array {
            return match ($msg) {
                'inc'   => ['count' => $model['count'] + 1],
                'dec'   => ['count' => $model['count'] - 1],
                'reset' => ['count' => 0],
                default => $model,
            };
        },
        view: function (array $model): Element {
            return Ui::window('Counter', [
                Ui::label("Count: {$model['count']}", 'count-label'),
                Ui::row([
                    Ui::button('-', 'dec', 'dec-btn'),
                    Ui::button('+', 'inc', 'inc-btn'),
                ]),
            ], id: 'main-window');
        },
    );
}

test('snapshot captures window and widget ids/roles/names', function () {
    $auto = (new Automation(autoApp(), new Canvas(headless: true)))->start();
    $snap = $auto->snapshot();

    expect($snap['title'])->toBe('Counter');
    expect($snap['widgets'])->toHaveCount(5); // window + label + row + 2 buttons

    $win = Snapshot::findById($snap, 'main-window');
    expect($win['role'])->toBe('window');
    expect($win['name'])->toBe('Counter');

    $inc = Snapshot::findById($snap, 'inc-btn');
    expect($inc['role'])->toBe('button');
    expect($inc['name'])->toBe('+');
    expect($inc['handler'])->toBe('inc');

    $lbl = Snapshot::findByText($snap, 'Count: 0');
    expect($lbl['id'])->toBe('count-label');

    expect(Snapshot::findByRole($snap, 'button'))->toHaveCount(2);
});

test('click by id drives the model and updates the snapshot', function () {
    $auto = (new Automation(autoApp(), new Canvas(headless: true)))->start();
    $auto->clickById('inc-btn');
    $auto->clickById('inc-btn');

    $lbl = Snapshot::findById($auto->snapshot(), 'count-label');
    expect($lbl['name'])->toBe('Count: 2');
});

test('click by text targets the matching button', function () {
    $auto = (new Automation(autoApp(), new Canvas(headless: true)))->start();
    $auto->clickText('-');

    $lbl = Snapshot::findById($auto->snapshot(), 'count-label');
    expect($lbl['name'])->toBe('Count: -1');
});

test('missing widget raises a clear error', function () {
    $auto = (new Automation(autoApp(), new Canvas(headless: true)))->start();
    expect(fn() => $auto->clickById('nope'))->toThrow(\RuntimeException::class);
});

test('record then replay reproduces the same end state', function () {
    $auto = (new Automation(autoApp(), new Canvas(headless: true)))->start();
    $rec = $auto->recorder();
    $rec->clickById('inc-btn');
    $rec->clickText('-');
    $rec->dispatch('inc');

    expect($auto->model()['count'])->toBe(1);
    expect($rec->script()->actions())->toHaveCount(3);

    $auto2 = (new Automation(autoApp(), new Canvas(headless: true)))->start();
    $auto2->replay($rec->script());
    expect($auto2->model()['count'])->toBe(1);
});

test('script round-trips through a file', function () {
    $auto = (new Automation(autoApp(), new Canvas(headless: true)))->start();
    $tmp = tempnam(sys_get_temp_dir(), 'ui3') . '.json';
    $auto->recorder()->clickById('inc-btn')->save($tmp);

    $auto2 = (new Automation(autoApp(), new Canvas(headless: true)))->start();
    $auto2->replay(Script::fromFile($tmp));
    expect($auto2->model()['count'])->toBe(1);

    unlink($tmp);
});
