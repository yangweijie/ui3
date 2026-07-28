<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// 运行（必须经由 bin/run.sh 设置库路径）:
//   bash bin/run.sh php -d ffi.enable=true examples/accessibility.php
use Yangweijie\Ui3\{App, Ui, Element};
use Yangweijie\Ui3\Backends\Canvas;
use Yangweijie\Ui3\Automation\Automation;

// 用 Ui::accessible 给控件附加语义 role / label / description。
$app = new App(
    init: static fn(): array => ['on' => false],
    update: static fn(array $m, string $msg): array => match ($msg) {
        'toggle' => [...$m, 'on' => !$m['on']],
        default => $m,
    },
    view: static function (array $m): Element {
        $title = Ui::accessible(Ui::heading('Settings', 'h'), role: 'heading', label: 'Settings');
        $btn = Ui::accessible(
            Ui::button($m['on'] ? 'On' : 'Off', 'toggle', 'toggle-btn'),
            role: 'switch',
            label: 'Enable feature',
            description: 'Toggles the experimental feature',
        );
        return Ui::window('Accessibility', [$title, $btn], width: 260, height: 120);
    },
);

// Headless：用自动化快照读取无障碍树，确认 role/label/description 已暴露。
$auto = (new Automation($app, new Canvas(headless: true)))->start();
$snap = $auto->snapshot();

foreach ($snap['widgets'] as $w) {
    if (($w['id'] ?? '') === 'toggle-btn') {
        printf("toggle-btn: role=%s label=%s desc=%s\n",
            $w['role'] ?? '?', $w['label'] ?? '', $w['description'] ?? '');
    }
}
// 整个快照暴露了语义化无障碍树（role/label/description 已写入）。
printf("snapshot widgets: %d, has heading=%s\n",
    count($snap['widgets']),
    in_array('heading', array_column($snap['widgets'], 'role'), true) ? 'yes' : 'no');
echo "OK\n";
