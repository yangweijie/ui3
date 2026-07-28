<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// 运行（必须经由 bin/run.sh 设置库路径）:
//   bash bin/run.sh php -d ffi.enable=true examples/counter.php            # headless
//   UI3_REAL_WINDOW=1 bash bin/run.sh php -d ffi.enable=true examples/counter.php  # 真实窗口
use Yangweijie\Ui3\{App, Ui};

$app = new App(
    init: fn(): array => ['count' => 0],
    update: function (array $model, string $msg): array {
        return match ($msg) {
            'increment' => ['count' => $model['count'] + 1],
            'decrement' => ['count' => $model['count'] - 1],
            'reset'     => ['count' => 0],
            default     => $model,
        };
    },
    view: function (array $model): \Yangweijie\Ui3\Element {
        return Ui::window('Counter', [
            Ui::label("Count: {$model['count']}"),
            Ui::row([
                Ui::button('-', 'decrement'),
                Ui::button('+', 'increment'),
                Ui::button('reset', 'reset'),
            ]),
        ], width: 280, height: 160);
    },
);

// Opens a real native window (Cocoa on macOS, Win32 on Windows, X11 on Linux).
// Clicking +/-/reset dispatches a message; the label is rebound on each update.
// 默认 headless（CI 安全）；设 UI3_REAL_WINDOW=1 才开真实窗口。
if (getenv('UI3_REAL_WINDOW')) {
    $app->run();
} else {
    // Headless demo: drive the counter and print the model so the run is visible
    // (no native window). Mirrors the button clicks you'd perform in the GUI.
    $backend = new \Yangweijie\Ui3\Backends\Canvas(headless: true);
    $app->start();
    $backend->mount($app->render(), fn(string $msg) => $app->dispatch($msg));
    printf("headless counter demo (start count = %d)\n", $app->model()['count']);
    foreach (['increment', 'increment', 'increment', 'decrement', 'reset'] as $msg) {
        $app->dispatch($msg);
        $backend->step();
        printf("  %-9s -> count = %d\n", $msg, $app->model()['count']);
    }
    $backend->quit();
    echo "done.\n";
}
