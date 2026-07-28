<?php
declare(strict_types=1);
/**
 * End-to-end verification of the REAL window key path (not headless).
 *
 * It mounts a real Canvas backend, focuses an input, and posts keystrokes
 * through ui3_host_post_key — which in a real window synthesizes a genuine
 * NSEvent and delivers it via window.keyDown: -> routeKey -> the PHP event_cb.
 * This is exactly the path physical typing uses, and the one automation could
 * never reach before (the inject queue is headless-only).
 *
 * Run: UI3_REAL_WINDOW=1 bash bin/run.sh php -d ffi.enable=true examples/verify_realkey.php
 */
require __DIR__ . '/../vendor/autoload.php';

use Yangweijie\Ui3\App;
use Yangweijie\Ui3\Backends\Canvas;
use Yangweijie\Ui3\Ui;

$init = fn(): array => ['user' => ''];
$update = function (array $m, string $msg, mixed $p = null): array {
    return match ($msg) {
        'set-user' => array_merge($m, ['user' => (string) $p]),
        default => $m,
    };
};
$view = function (array $m): \Yangweijie\Ui3\Element {
    return Ui::window('Verify', [
        Ui::input($m['user'], 'type here', 'set-user', 'user-in'),
    ]);
};

$app = new App($init, $update, $view);
$app->start();

$backend = new Canvas(headless: false);
$backend->mount($app->render(), function ($msg, $payload = null) use ($app) {
    return $app->dispatch($msg, $payload);
});

echo "host headless? " . ($backend->isHeadless() ? "yes (no display)\n" : "no (real window)\n");

$backend->focus('user-in');
foreach (str_split('alice') as $c) {
    $backend->postKey(0, false, $c);
}
$backend->step();

$got = $app->model()['user'];
echo "model['user'] = " . var_export($got, true) . "\n";
$ok = $got === 'alice';
echo $ok ? "VERIFY PASS: real Cocoa keyDown path delivers keystrokes\n"
         : "VERIFY FAIL\n";
$backend->quit();
exit($ok ? 0 : 1);
