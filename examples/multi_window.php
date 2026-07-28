<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// 运行（必须经由 bin/run.sh 设置库路径）:
//   bash bin/run.sh php -d ffi.enable=true examples/multi_window.php
//   UI3_REAL_WINDOW=1 bash bin/run.sh php -d ffi.enable=true examples/multi_window.php   # 真实多窗口
use Yangweijie\Ui3\{App, Ui, Element};
use Yangweijie\Ui3\Backends\Canvas;

// 一个主窗口 + 两个通过 openWindow 注册的附加窗口（window_state 管理生命周期与焦点）。
$main = new App(
    init: static fn(): array => ['active' => 'main'],
    update: static fn(array $m, string $msg): array => match ($msg) {
        'focus:tools' => [...$m, 'active' => 'tools'],
        'focus:help' => [...$m, 'active' => 'help'],
        default => $m,
    },
    view: static function (array $m): Element {
        return Ui::window('Main', [
            Ui::label("Active: {$m['active']}"),
            Ui::button('Open Tools', 'focus:tools', 'open-tools'),
            Ui::button('Open Help', 'focus:help', 'open-help'),
        ], width: 240, height: 160, id: 'main');
    },
);

$main->openWindow('tools', 'Tools', 200, 150);
$main->openWindow('help', 'Help', 200, 150);

printf("windows open: %d\n", $main->windows()->count());
foreach ($main->windows()->list() as $w) {
    printf("  - %s (%dx%d)\n", $w['title'], $w['width'], $w['height']);
}

$main->focusWindow('help');
printf("active window: %s\n", $main->activeWindow());

$main->closeWindow('tools');
printf("after close tools: %d open, active=%s\n", $main->windows()->count(), $main->activeWindow());

if (getenv('UI3_REAL_WINDOW')) {
    $main->run();
    return;
}
echo "done (headless).\n";
