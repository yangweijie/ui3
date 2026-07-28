<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// 场景：设置面板 —— 演示 toolbar / segmented / toggle / slider / search 的组合交互。
// 用 Automation 切换主题、开关、拖音量、过滤，并打印最终模型状态。
// 运行:
//   bash bin/run.sh php -d ffi.enable=true examples/settings_panel.php
//   UI3_REAL_WINDOW=1 bash bin/run.sh php -d ffi.enable=true examples/settings_panel.php  # 真实窗口

use Yangweijie\Ui3\{App, Ui, Element};
use Yangweijie\Ui3\Backends\Canvas;
use Yangweijie\Ui3\Automation\Automation;

$model = [
    'theme' => 0,
    'wifi' => true,
    'bluetooth' => false,
    'volume' => 50,
    'filter' => '',
];

$update = function (array $m, string $msg, mixed $p = null): array {
    return array_merge($m, match ($msg) {
        'set-theme' => ['theme' => (int) $p],
        'set-wifi' => ['wifi' => (bool) $p],
        'set-bt' => ['bluetooth' => (bool) $p],
        'set-vol' => ['volume' => (int) $p],
        'set-filter' => ['filter' => (string) $p],
        default => [],
    });
};

$themeName = fn (int $t): string => ['Light', 'Dark', 'System'][$t] ?? 'Light';

$view = function (array $m) use ($themeName): Element {
    return Ui::window('Settings', [
        Ui::toolbar([
            Ui::iconButton('⚙', 'Settings', 'tb', 'tb-settings'),
            Ui::iconButton('+', 'Add', 'tb', 'tb-add'),
        ], 'the-toolbar'),
        Ui::column([
            Ui::segmented(['Light', 'Dark', 'System'], $m['theme'], 'set-theme', 'theme-seg'),
            Ui::toggle('Wi-Fi', $m['wifi'], 'set-wifi', 'wifi-toggle'),
            Ui::toggle('Bluetooth', $m['bluetooth'], 'set-bt', 'bt-toggle'),
            Ui::slider(0, 100, $m['volume'], 'set-vol', 'vol-slider'),
            Ui::searchField($m['filter'], 'Filter…', 'set-filter', 'filter-search'),
            Ui::label('Theme: ' . $themeName($m['theme']) . '  Volume: ' . $m['volume']),
        ]),
        Ui::statusbar([Ui::label('Wi-Fi ' . ($m['wifi'] ? 'on' : 'off') . ' · BT ' . ($m['bluetooth'] ? 'on' : 'off'))], 'the-status'),
    ], 360, 420);
};

$app = new App($model, $update, $view);

if (getenv('UI3_REAL_WINDOW')) {
    $app->run();
} else {
    $backend = new Canvas(headless: true);
    $a = new Automation($app, $backend);
    $a->start();

    $a->setSegmented('theme-seg', 1);   // Dark
    $a->setToggle('wifi-toggle', false); // 关 Wi-Fi
    $a->setToggle('bt-toggle', true);    // 开蓝牙
    $a->slideTo('vol-slider', 75);       // 音量调到 75
    $a->input('filter-search', 'blue');  // 过滤关键字

    $m = $a->model();
    printf("theme=%s wifi=%s bluetooth=%s volume=%d filter=%s\n",
        $themeName($m['theme']), var_export($m['wifi'], true), var_export($m['bluetooth'], true), $m['volume'], $m['filter']);

    $backend->quit();
}
