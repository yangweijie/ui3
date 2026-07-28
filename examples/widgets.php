<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// 运行（必须经由 bin/run.sh 设置库路径）:
//   bash bin/run.sh php -d ffi.enable=true examples/widgets.php
//   UI3_REAL_WINDOW=1 bash bin/run.sh php -d ffi.enable=true examples/widgets.php   # 真实窗口
use Yangweijie\Ui3\{App, Ui, Element};
use Yangweijie\Ui3\Backends\Canvas;
use Yangweijie\Ui3\Automation\Automation;

$build = static function (): App {
    return new App(
        init: static fn(): array => ['name' => '', 'agree' => false, 'level' => 5, 'choice' => 0, 'picked' => -1],
        update: static function (array $model, string $msg, mixed $payload = null): array {
            return match ($msg) {
                'name'   => [...$model, 'name' => (string) $payload],
                'agree'  => [...$model, 'agree' => (bool) $payload],
                'level'  => [...$model, 'level' => (int) $payload],
                'choice' => [...$model, 'choice' => (int) $payload],
                'pick'   => [...$model, 'picked' => (int) $payload],
                'submit' => [...$model, 'name' => $model['name'] ?: '(none)'],
                default  => $model,
            };
        },
        view: static function (array $model): Element {
            return Ui::window('Widget gallery', [
                Ui::heading('Registration', 'h'),
                Ui::input($model['name'], 'Your name', 'name', 'name-input'),
                Ui::checkbox('I agree', $model['agree'], 'agree', 'agree-box'),
                Ui::slider(0, 10, $model['level'], 'level', 'level-slider'),
                Ui::select(['Low', 'Mid', 'High'], $model['choice'], 'choice', 'choice-select'),
                Ui::list(['Apple', 'Banana', 'Cherry'], $model['picked'], 'pick', 'pick-list'),
                Ui::progress(0.4, 'prog'),
                Ui::panel('Actions', [
                    Ui::button('Submit', 'submit', 'submit-btn'),
                ], 'grp'),
            ], width: 360, height: 420);
        },
    );
};

if (getenv('UI3_REAL_WINDOW')) {
    // Opens a real native window (Cocoa/Win32/X11) with live controls.
    $build()->run(new Canvas());
    return;
}

// Headless automation demo over the full widget catalog.
$auto = (new Automation($build(), new Canvas(headless: true)))->start();

$snap = $auto->snapshot();
printf("window '%s' has %d widgets\n", $snap['title'], count($snap['widgets']));

$auto->input('name-input', 'Ada');
$auto->setChecked('agree-box', true);
$auto->slideTo('level-slider', 8);
$auto->selectOption('choice-select', 2);
$auto->selectListItem('pick-list', 1);
$auto->clickById('submit-btn');

$m = $auto->model();
printf("name=%s agree=%s level=%d choice=%d picked=%d\n",
    $m['name'], var_export($m['agree'], true), $m['level'], $m['choice'], $m['picked']);

$snap = $auto->snapshot();
printf("slider value now %d, select selected %d, list selected %d\n",
    Snapshot_value($snap, 'level-slider'),
    Snapshot_selected($snap, 'choice-select'),
    Snapshot_selected($snap, 'pick-list'));
echo "OK\n";

function Snapshot_value(array $snap, string $id): int
{
    return Snapshot_find($snap, $id)['state']['value'] ?? -1;
}
function Snapshot_selected(array $snap, string $id): int
{
    return Snapshot_find($snap, $id)['state']['selected'] ?? -1;
}
function Snapshot_find(array $snap, string $id): array
{
    foreach ($snap['widgets'] as $w) {
        if (($w['id'] ?? null) === $id) {
            return $w;
        }
    }
    return ['state' => []];
}
