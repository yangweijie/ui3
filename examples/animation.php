<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// 运行（必须经由 bin/run.sh 设置库路径）:
//   bash bin/run.sh php -d ffi.enable=true examples/animation.php
//   UI3_REAL_WINDOW=1 bash bin/run.sh php -d ffi.enable=true examples/animation.php   # 真实窗口
use Yangweijie\Ui3\{App, Ui, Animation};
use Yangweijie\Ui3\Backends\Canvas;

// 1) Animation 数学原语：进度/插值/缓动曲线（无需窗口，headless 直接可用）。
printf("easing curves @ t=0.5:\n");
foreach (['linear', 'easeIn', 'easeOut', 'easeInOut', 'easeOutBack', 'step'] as $name) {
    printf("  %-12s -> %.3f\n", $name, Animation::ease($name, 0.5));
}
printf("progress(400,1000) = %.2f  lerp(0,100,0.25) = %.1f\n",
    Animation::progress(400, 1000), Animation::lerp(0, 100, 0.25));

// 2) 在视图里用 fadeIn / animate 描述进场动画；后端在每帧推进时钟并插值。
$app = new App(
    init: static fn(): array => ['shown' => false],
    update: static fn(array $m, string $msg): array => match ($msg) {
        'show' => [...$m, 'shown' => true],
        default => $m,
    },
    view: static function (array $m): \Yangweijie\Ui3\Element {
        $card = Ui::panel('Welcome', [Ui::label($m['shown'] ? 'Hello!' : 'Click to reveal')], 'card');
        $card = Ui::fadeIn($card, durationMs: 300);
        return Ui::window('Animation demo', [$card, Ui::button('Reveal', 'show', 'reveal-btn')],
            width: 260, height: 140);
    },
);

if (getenv('UI3_REAL_WINDOW')) {
    $app->run();
    return;
}

// Headless：挂载后推进若干帧，验证 fadeIn 元素可被识别与渲染。
$backend = new Canvas(headless: true);
$app->start();
$backend->mount($app->render(), fn(string $msg) => $app->dispatch($msg));
printf("mounted animation demo; card has fadeIn anim = %s\n",
    ($backend->layout() !== [] ? 'yes' : 'no'));
$backend->quit();
echo "done.\n";
