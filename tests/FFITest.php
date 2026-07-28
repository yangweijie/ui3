<?php
declare(strict_types=1);

use Yangweijie\Ui3\{App, Ui, Element};
use Yangweijie\Ui3\Backends\Canvas;
use Yangweijie\Ui3\Canvas\Layout;

$libPath = match (PHP_OS_FAMILY) {
    'Windows' => __DIR__ . '/../build/libui3.dll',
    'Darwin'  => __DIR__ . '/../build/libui3.dylib',
    default   => __DIR__ . '/../build/libui3.so',
};

test('canvas backend loads, draws headless and dispatches a button click', function () use ($libPath) {
    if (!file_exists($libPath)) {
        $this->markTestSkipped("libui3 not built at {$libPath}; run `bash ext/build.sh`");
        return;
    }

    $app = new App(
        init: fn(): array => ['count' => 0],
        update: function (array $model, string $msg): array {
            return $msg === 'inc' ? ['count' => $model['count'] + 1] : $model;
        },
        view: function (array $model): Element {
            return Ui::window('Counter', [
                Ui::label("Count: {$model['count']}"),
                Ui::button('+', 'inc'),
            ]);
        },
    );

    // headless canvas: offscreen surface, no window opened.
    $backend = new Canvas(headless: true);
    $app->start();
    $backend->mount($app->render(), fn(string $msg) => $app->dispatch($msg));

    // First step paints the offscreen frame (exercises the full Cairo pipeline).
    expect($backend->step())->toBe(1);

    // Locate the button from our own layout and click its center.
    $nodes = Layout::compute($app->render());
    $btn = null;
    foreach ($nodes as $n) {
        if ($n->type === 'button') { $btn = $n; break; }
    }
    expect($btn)->not->toBeNull();
    $backend->injectPointer($btn->x + $btn->w / 2, $btn->y + $btn->h / 2, true);
    expect($backend->step())->toBe(1);

    expect($app->model()['count'])->toBe(1);
    $backend->quit();
});

test('canvas backend drives the full widget catalog headless', function () use ($libPath) {
    if (!file_exists($libPath)) {
        $this->markTestSkipped("libui3 not built at {$libPath}; run `bash ext/build.sh`");
        return;
    }

    $app = new App(
        init: fn(): array => ['name' => '', 'agree' => false, 'level' => 5, 'choice' => 0, 'picked' => -1],
        update: function (array $model, string $msg, mixed $payload = null): array {
            return match ($msg) {
                'name'  => [...$model, 'name' => (string) $payload],
                'agree' => [...$model, 'agree' => (bool) $payload],
                'level' => [...$model, 'level' => (int) $payload],
                'choice' => [...$model, 'choice' => (int) $payload],
                'pick'  => [...$model, 'picked' => (int) $payload],
                default => $model,
            };
        },
        view: function (array $model): Element {
            return Ui::window('Form', [
                Ui::heading('Profile'),
                Ui::input($model['name'], 'Your name', 'name', 'name-input'),
                Ui::checkbox('Agree', $model['agree'], 'agree', 'agree-box'),
                Ui::slider(0, 10, $model['level'], 'level', 'level-slider'),
                Ui::select(['Low', 'Mid', 'High'], $model['choice'], 'choice', 'choice-select'),
                Ui::list(['Apple', 'Banana', 'Cherry'], $model['picked'], 'pick', 'pick-list'),
                Ui::progress(0.4),
                Ui::image('/tmp/x.png', 'img'),
                Ui::divider(),
                Ui::panel('Group', [Ui::label('inside')]),
            ]);
        },
    );

    $backend = new Canvas(headless: true);
    $app->start();
    $backend->mount($app->render(), fn(string $msg, mixed $payload = null) => $app->dispatch($msg, $payload));

    expect($backend->step())->toBe(1); // paints the whole catalog offscreen

    $nodes = Layout::compute($app->render());
    $byType = fn(string $t): array => array_values(array_filter($nodes, fn($n) => $n->type === $t));

    $check = $byType('checkbox')[0] ?? null;
    if ($check) {
        $backend->injectPointer($check->x + 10, $check->y + 10, true);
        $backend->step();
    }
    expect($app->model()['agree'])->toBeTrue();

    $list = $byType('list')[0] ?? null;
    if ($list) {
        $backend->injectPointer($list->x + 10, $list->y + 4 + 20 * 1 + 10, true);
        $backend->step();
    }
    expect($app->model()['picked'])->toBe(1);

    $backend->quit();
});
