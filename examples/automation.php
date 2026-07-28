<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// 运行（必须经由 bin/run.sh 设置库路径，headless 自动化演示）:
//   bash bin/run.sh php -d ffi.enable=true examples/automation.php
use Yangweijie\Ui3\{App, Ui, Element};
use Yangweijie\Ui3\Backends\Canvas;
use Yangweijie\Ui3\Automation\{Automation, Script};

// A counter app whose controls carry stable ids so automation can target them
// by identity. This is the contract a real headless test would rely on.
$build = static function (): App {
    return new App(
        init: static fn(): array => ['count' => 0],
        update: static function (array $model, string $msg): array {
            return match ($msg) {
                'inc'   => ['count' => $model['count'] + 1],
                'dec'   => ['count' => $model['count'] - 1],
                'reset' => ['count' => 0],
                default => $model,
            };
        },
        view: static function (array $model): Element {
            return Ui::window('Counter', [
                Ui::label("Count: {$model['count']}", 'count-label'),
                Ui::row([
                    Ui::button('-', 'dec', 'dec-btn'),
                    Ui::button('+', 'inc', 'inc-btn'),
                    Ui::button('reset', 'reset', 'reset-btn'),
                ]),
            ], id: 'main-window');
        },
    );
};

// 1) Start an automation session over the headless backend (no window opened).
$auto = (new Automation($build(), new Canvas(headless: true)))->start();

// 2) Snapshot the live UI tree — ids / roles / names / bounds / handlers.
$snap = $auto->snapshot();
printf("window: %s (%dx%d), %d widgets\n",
    $snap['title'], $snap['width'], $snap['height'], count($snap['widgets']));

// 3) Drive the UI by widget identity — no coordinates, no screen.
$auto->clickById('inc-btn');
$auto->clickById('inc-btn');
$auto->clickText('reset');
printf("after inc x2 + reset:  %s\n", Snapshot_name($auto));

$auto->dispatch('reset');
$auto->clickById('dec-btn');
printf("after reset + dec:     %s\n", Snapshot_name($auto));

// 4) Record a short interaction as a replayable script and persist it.
$tmp = sys_get_temp_dir() . '/ui3-automation-' . getmypid() . '.json';
$auto->recorder()
    ->clickById('inc-btn')
    ->clickById('inc-btn')
    ->clickText('-')
    ->save($tmp);
printf("recorded script -> %s\n", $tmp);

// 5) Replay the script against a fresh app and confirm the same end state.
$auto2 = (new Automation($build(), new Canvas(headless: true)))->start();
$auto2->replay(Script::fromFile($tmp));
printf("replayed end state:    %s (expected Count: 1)\n", Snapshot_name($auto2));

unlink($tmp);
echo "OK\n";

/** Read the live label text out of a snapshot. */
function Snapshot_name(Automation $auto): string
{
    $snap = $auto->snapshot();
    foreach ($snap['widgets'] as $w) {
        if (($w['id'] ?? null) === 'count-label') {
            return $w['name'];
        }
    }
    return '(no label)';
}
