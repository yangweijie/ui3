<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// 演示 native SDK 的每一个组件（native-sdk.d.ts 的 NativeSdkViewKind）：
//   toolbar, titlebar_accessory, sidebar, statusbar, toggle, icon_button,
//   segmented, search, list(+list_item), split, stack, webview, gpu_surface
// 运行（必须经由 bin/run.sh 设置库路径）:
//   bash bin/run.sh php -d ffi.enable=true examples/native_components.php            # headless 快照
//   UI3_REAL_WINDOW=1 bash bin/run.sh php -d ffi.enable=true examples/native_components.php  # 真实窗口

use Yangweijie\Ui3\{App, Ui, Element};

$model = [
    'tog' => 0,
    'seg' => 0,
    'search' => '',
    'icon' => 0,
    'picked' => -1,
    'split' => 50,
];

$update = function (array $m, string $msg, mixed $p = null): array {
    return array_merge($m, match ($msg) {
        'toggle' => ['tog' => $p ? 1 : 0],
        'seg' => ['seg' => (int) $p],
        'search' => ['search' => (string) $p],
        'icon' => ['icon' => 1],
        'pick' => ['picked' => (int) $p],
        'split' => ['split' => (int) $p],
        default => [],
    });
};

$view = function (array $m): Element {
    return Ui::window('Native Components', [
        Ui::toolbar([
            Ui::iconButton('⚙', 'Settings', 'icon', 'tb-settings'),
            Ui::iconButton('+', 'Add', 'icon', 'tb-add'),
        ], 'the-toolbar'),
        Ui::titlebarAccessory([Ui::label('Title')], 'the-titlebar'),
        Ui::sidebar([
            Ui::button('Home', 'home', 'sb-home'),
            Ui::button('Docs', 'docs', 'sb-docs'),
        ], 'the-sidebar'),
        Ui::column([
            Ui::toggle('Enable feature', (bool) $m['tog'], 'toggle', 'the-toggle'),
            Ui::segmented(['One', 'Two', 'Three'], $m['seg'], 'seg', 'the-seg'),
            Ui::searchField($m['search'], 'Search…', 'search', 'the-search'),
            Ui::iconButton('★', 'Star', 'icon', 'the-icon'),
            Ui::stack([Ui::label('Left'), Ui::label('Right')], 'horizontal', 'the-stack'),
            Ui::list([
                Ui::listItem('📄', 'Document', 'A text file', 'li-doc'),
                Ui::listItem('🖼', 'Image', 'A picture', 'li-img'),
            ], -1, 'pick', 'the-list'),
            Ui::split([Ui::label('Pane A'), Ui::label('Pane B')], 'horizontal', 0.5, 'split', 'the-split'),
            Ui::webview('https://example.com', 'the-web'),
            Ui::gpuSurface(200, 120, 'the-gpu'),
        ]),
        Ui::statusbar([Ui::label('Ready')], 'the-status'),
    ], 520, 420);
};

$app = new App($model, $update, $view);

// 默认 headless（CI 安全）；设 UI3_REAL_WINDOW=1 才开真实窗口。
if (getenv('UI3_REAL_WINDOW')) {
    $app->run();
} else {
    $backend = new \Yangweijie\Ui3\Backends\Canvas(headless: true);
    $app->start();
    $backend->mount($app->render(), fn(string $msg, $p = null) => $app->dispatch($msg, $p));
    $backend->step();
    $backend->quit();
    $snap = (new \Yangweijie\Ui3\Automation\Automation($app, $backend))->snapshot();
    $roles = array_unique(array_column($snap['widgets'], 'role'));
    printf("headless render ok — native kinds present: %s\n", implode(', ', $roles));
}
