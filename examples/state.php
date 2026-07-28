<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// 运行（无需原生库，纯 PHP 即可）:
//   php examples/state.php
use Yangweijie\Ui3\{App, Ui, Element, Signal, Reconcile};
use Yangweijie\Ui3\Backends\Canvas;
use Yangweijie\Ui3\Automation\Automation;

// 1) Signal：最小响应式值容器（set/update/subscribe）。
$count = new Signal(0);
$seen = [];
$count->subscribe(static function (int $v) use (&$seen): void {
    $seen[] = $v;
});
$count->set(1);
$count->update(static fn(int $v): int => $v + 10);
printf("signal log: [%s]\n", implode(', ', $seen));

// 2) Reconcile：按稳定 key/id 做列表 diff（same/moved/added/removed）。
$prev = [
    Ui::label('A', 'a'),
    Ui::label('B', 'b'),
];
$next = [
    Ui::label('B', 'b'), // 位置变化 -> moved
    Ui::label('C', 'c'), // 新 -> added
];
foreach (Reconcile::keyed($prev, $next) as $e) {
    printf("  %s: %s\n", $e['key'], $e['status']);
}

// 3) breakpoint：窗口宽度的响应式断点。
foreach ([360, 600, 1200] as $w) {
    printf("width %d -> %s\n", $w, Ui::breakpoint($w));
}

// 4) 在真实视图里用 Signal 驱动响应（headless 验证可挂载）。
$app = new App(
    init: static fn(): array => ['n' => $count->get()],
    update: static fn(array $m, string $msg): array => match ($msg) {
        'inc' => [...$m, 'n' => $m['n'] + 1],
        default => $m,
    },
    view: static fn(array $m): Element => Ui::window('Reactive', [Ui::label("n={$m['n']}")], id: 'rx'),
);
$auto = (new Automation($app, new Canvas(headless: true)))->start();
$auto->dispatch('inc');
printf("reactive model n=%d\n", $auto->model()['n']);
echo "done.\n";
