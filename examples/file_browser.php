<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// 场景：文件浏览器 —— 演示 sidebar / split / list(+list_item) / 拖拽分隔 的组合交互。
// 用 Automation 选中侧栏目录、点击文件列表项（真实指针命中 list_item 行）、
// 拖动分隔条，并打印当前选中文件与分隔位置。
// 运行:
//   bash bin/run.sh php -d ffi.enable=true examples/file_browser.php
//   UI3_REAL_WINDOW=1 bash bin/run.sh php -d ffi.enable=true examples/file_browser.php  # 真实窗口

use Yangweijie\Ui3\{App, Ui, Element};
use Yangweijie\Ui3\Backends\Canvas;
use Yangweijie\Ui3\Automation\Automation;

$files = [
    'home' => [
        Ui::listItem('📁', 'Projects', '12 items', 'row-projects'),
        Ui::listItem('🖼', 'Pictures', '38 items', 'row-pics'),
        Ui::listItem('📄', 'README.md', '2 KB', 'row-readme'),
    ],
    'docs' => [
        Ui::listItem('📄', 'guide.pdf', '1.2 MB', 'row-guide'),
        Ui::listItem('📄', 'notes.txt', '4 KB', 'row-notes'),
    ],
];

$model = [
    'dir' => 'home',
    'selected' => -1,
    'split' => 40,
];

$update = function (array $m, string $msg, mixed $p = null) use ($files): array {
    return array_merge($m, match ($msg) {
        'dir-home' => ['dir' => 'home', 'selected' => -1],
        'dir-docs' => ['dir' => 'docs', 'selected' => -1],
        'pick' => ['selected' => (int) $p],
        'set-split' => ['split' => (int) $p],
        default => [],
    });
};

$view = function (array $m) use ($files): Element {
    $rows = $files[$m['dir']] ?? [];
    $sel = $m['selected'];
    $detail = ($sel >= 0 && isset($rows[$sel]))
        ? $rows[$sel]->prop('title') . ' — ' . ($rows[$sel]->prop('subtitle') ?? '')
        : 'No file selected';
    return Ui::window('Files', [
        Ui::sidebar([
            Ui::button('Home', 'dir-home', 'sb-home'),
            Ui::button('Docs', 'dir-docs', 'sb-docs'),
        ], 'the-sidebar'),
        Ui::split([
            Ui::list($rows, $m['selected'], 'pick', 'file-list'),
            Ui::column([
                Ui::heading('Preview'),
                Ui::label($detail),
            ]),
        ], 'horizontal', $m['split'] / 100, 'set-split', 'the-split'),
    ], 480, 360);
};

$app = new App($model, $update, $view);

if (getenv('UI3_REAL_WINDOW')) {
    $app->run();
} else {
    $backend = new Canvas(headless: true);
    $a = new Automation($app, $backend);
    $a->start();

    // 在 Home 目录下选中 "Pictures"
    $a->clickById('row-pics');
    printf("[home]   selected='%s' (index %d)\n", $a->model()['selected'] >= 0 ? 'Pictures' : 'none', $a->model()['selected']);

    // 切换到 Docs 目录
    $a->clickById('sb-docs');
    printf("[dir]    now in '%s', selected reset to %d\n", $a->model()['dir'], $a->model()['selected']);

    // 在 Docs 目录下选中 "guide.pdf"
    $a->clickById('row-guide');
    printf("[docs]   selected='%s' (index %d)\n", $a->model()['selected'] >= 0 ? 'guide.pdf' : 'none', $a->model()['selected']);

    // 拖动分隔条到 30%（真实 onChange 派发）
    $a->setSplit('the-split', 30);
    printf("[split]  divider at %d%%\n", $a->model()['split']);

    $backend->quit();
}
