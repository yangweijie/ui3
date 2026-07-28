<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// 运行:
//   bash bin/run.sh php -d ffi.enable=true examples/systems.php
use Yangweijie\Ui3\{App, Ui, Element, Extensions, Assets};
use Yangweijie\Ui3\Security\Capabilities;
use Yangweijie\Ui3\Security\SecurityException;

// 1) Extensions：生命周期钩子总线（beforeRender/afterRender/afterUpdate）。
$app = new App(
    init: static fn(): array => ['n' => 0],
    update: static fn(array $m, string $msg): array => match ($msg) {
        'inc' => [...$m, 'n' => $m['n'] + 1],
        default => $m,
    },
    view: static fn(array $m): Element => Ui::window('Sys', [Ui::label((string) $m['n'])], id: 'sys'),
);
$fired = [];
$app->extend('afterUpdate', static function (array $m) use (&$fired): void {
    $fired[] = $m['n'];
});
$app->start();
$app->dispatch('inc');
$app->dispatch('inc');
printf("afterUpdate fired with: [%s]\n", implode(', ', $fired));

// 2) Capabilities：敏感操作 fail-closed，未授权即抛 SecurityException。
$caps = new Capabilities();
$caps->grant('fs.read');
try {
    $caps->demand('fs.read');
    echo "fs.read: allowed\n";
    $caps->demand('fs.write'); // 未授权
    echo "fs.write: allowed (should not happen)\n";
} catch (SecurityException $e) {
    printf("fs.write denied: %s\n", $e->getMessage());
}

// 3) Assets：逻辑名 -> URL，可选 mtime 缓存破坏。
$assets = new Assets(base: '/static');
$assets->register('icon:save', 'icons/save.png');
printf("asset url: %s\n", $assets->url('icon:save'));
echo "done.\n";
