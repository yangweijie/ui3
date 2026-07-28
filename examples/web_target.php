<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// 运行（无需原生库，纯 PHP 即可）:
//   php examples/web_target.php
use Yangweijie\Ui3\{Ui, Element};
use Yangweijie\Ui3\Backends\Html;

// Web 目标后端：把 Element 树渲染为带 data-role/data-id 的语义 HTML，
// 复用与 Canvas 相同的无障碍/自动化契约。真实场景下由 HTTP 服务器托管。
$view = fn(): Element => Ui::window('Web App', [
    Ui::accessible(Ui::heading('Sign in', 'h'), role: 'heading', label: 'Sign in'),
    Ui::input('', 'Email', 'email', 'email-input'),
    Ui::button('Submit', 'submit', 'submit-btn'),
], width: 320, height: 200);

$backend = new Html(themeName: \Yangweijie\Ui3\Theme::LIGHT);
$backend->mount($view(), fn(string $msg) => null);

echo $backend->html() . PHP_EOL;
echo "--- role/label exposed for automation: ---\n";
echo preg_replace('/></', ">\n<", $backend->html()) . PHP_EOL;
