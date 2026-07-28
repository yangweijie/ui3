<?php
declare(strict_types=1);

use Yangweijie\Ui3\{App, Ui, Element};
use Yangweijie\Ui3\Backends\Headless;

function counterApp(): App
{
    return new App(
        init: fn(): array => ['count' => 0],
        update: function (array $model, string $msg): array {
            return match ($msg) {
                'inc' => ['count' => $model['count'] + 1],
                'dec' => ['count' => $model['count'] - 1],
                default => $model,
            };
        },
        view: function (array $model): Element {
            return Ui::window('Counter', [
                Ui::label("Count: {$model['count']}"),
                Ui::row([
                    Ui::button('-', 'dec'),
                    Ui::button('+', 'inc'),
                ]),
            ]);
        },
    );
}

test('initial model is zero', function () {
    $app = counterApp();
    expect($app->start())->toBe(['count' => 0]);
});

test('dispatch updates the model immutably', function () {
    $app = counterApp();
    $app->start();

    expect($app->dispatch('inc')['count'])->toBe(1);
    expect($app->dispatch('inc')['count'])->toBe(2);
    expect($app->dispatch('dec')['count'])->toBe(1);
});

test('unknown message leaves the model unchanged', function () {
    $app = counterApp();
    $app->start();

    expect($app->dispatch('nope')['count'])->toBe(0);
});

test('render reflects the current model', function () {
    $app = counterApp();
    $app->start();
    $app->dispatch('inc');

    $tree = $app->render();
    expect($tree->type)->toBe('window');

    $leaves = ui3_collect_leaves($tree);
    expect($leaves[0]->type)->toBe('label');
    expect($leaves[0]->prop('text'))->toBe('Count: 1');
    expect($leaves[1]->prop('onClick'))->toBe('dec');
    expect($leaves[2]->prop('onClick'))->toBe('inc');
});

test('headless backend drives messages and records events', function () {
    $app = counterApp();
    $backend = new Headless();

    $app->run($backend); // non-blocking for Headless

    expect($backend->root()->type)->toBe('window');

    $backend->click('inc');
    $backend->click('inc');

    expect($app->model()['count'])->toBe(2);
    expect($backend->events)->toContainEqual(['type' => 'click', 'msg' => 'inc']);
    expect($backend->events)->toContainEqual(['type' => 'update']);
});
