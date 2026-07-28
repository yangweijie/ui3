<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yangweijie\Ui3\{App, Ui};

// 运行（必须经由 bin/run.sh 设置库路径）:
//   bash bin/run.sh php -d ffi.enable=true examples/canvas.php            # headless 渲染演示
//   UI3_REAL_WINDOW=1 bash bin/run.sh php -d ffi.enable=true examples/canvas.php  # 真实窗口
// Canvas backend demo: a real AppKit window (macOS) drawn entirely with Cairo.
// The window hosts no native controls — every widget is painted by PHP.
$app = new App(
    init: fn(): array => ['n' => 0, 'agree' => false],
    update: function (array $model, string $msg, mixed $payload = null): array {
        return match ($msg) {
            'inc'    => ['n' => $model['n'] + 1, 'agree' => $model['agree']],
            'agree'  => ['n' => $model['n'], 'agree' => (bool) $payload],
            default  => $model,
        };
    },
    view: function (array $model): \Yangweijie\Ui3\Element {
        return Ui::window('Canvas demo', [
            Ui::heading('Hello Canvas', 'h'),
            Ui::label("Clicks: {$model['n']}"),
            Ui::button('Click me', 'inc'),
            Ui::checkbox('I agree', $model['agree'], 'agree'),
        ], width: 320, height: 200);
    },
);

// 默认 headless 渲染演示（CI 安全）；设 UI3_REAL_WINDOW=1 开真实 Cocoa/Win32/X11 窗口。
if (getenv('UI3_REAL_WINDOW')) {
    $app->run();
} else {
    $backend = new \Yangweijie\Ui3\Backends\Canvas(headless: true);
    $app->start();
    $backend->mount($app->render(), fn(string $msg, $p = null) => $app->dispatch($msg, $p));
    $backend->step();
    $backend->quit();
    printf("headless render ok (%d frames painted)\n", $backend->framesDrawn());
}
