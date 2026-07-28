<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// 场景：登录表单 —— 演示 input / toggle / checkbox / button 的组合交互。
// 用 Automation 走真实键盘与指针路径（聚焦→逐字输入→点击），验证登录逻辑。
// 运行:
//   bash bin/run.sh php -d ffi.enable=true examples/login_form.php
//   UI3_REAL_WINDOW=1 bash bin/run.sh php -d ffi.enable=true examples/login_form.php  # 真实窗口

use Yangweijie\Ui3\{App, Ui, Element};
use Yangweijie\Ui3\Backends\Canvas;
use Yangweijie\Ui3\Automation\Automation;

$model = [
    'user' => '',
    'pass' => '',
    'show' => false,
    'remember' => false,
    'logged_in' => false,
    'error' => '',
];

$update = function (array $m, string $msg, mixed $p = null): array {
    return array_merge($m, match ($msg) {
        'set-user' => ['user' => (string) $p],
        'set-pass' => ['pass' => (string) $p],
        'toggle-show' => ['show' => (bool) $p],
        'toggle-remember' => ['remember' => (bool) $p],
        'login' => (($m['user'] !== '' && $m['pass'] !== '')
            ? ['logged_in' => true, 'error' => '']
            : ['logged_in' => false, 'error' => '用户名和密码不能为空']),
        default => [],
    });
};

$view = function (array $m): Element {
    return Ui::window('Login', [
        Ui::heading('Sign in'),
        Ui::label('Username'),
        Ui::input($m['user'], 'username', 'set-user', 'user-in'),
        Ui::label('Password'),
        Ui::input($m['pass'], 'password', 'set-pass', 'pass-in'),
        Ui::toggle('Show password', $m['show'], 'toggle-show', 'show-toggle'),
        Ui::checkbox('Remember me', $m['remember'], 'toggle-remember', 'remember-box'),
        Ui::button('Login', 'login', 'login-btn'),
        Ui::label($m['logged_in'] ? 'Welcome, ' . $m['user'] . '!' : $m['error']),
    ], 320, 360);
};

$app = new App($model, $update, $view);

if (getenv('UI3_REAL_WINDOW')) {
    // 真实窗口：起自动化服务器，让 AI/测试可连上正在运行的窗口读树、驱动、断言。
    // 端口可通过 UI3_AUTO_PORT 指定（默认 8080）。
    $port = (int) (getenv('UI3_AUTO_PORT') ?: 8080);
    $app->enableAutomation($port)->run();
} else {
    $backend = new Canvas(headless: true);
    $a = new Automation($app, $backend);
    $a->start();

    // 1) 空提交 -> 应报错
    $a->clickById('login-btn');
    printf("[empty]  logged_in=%s error=%s\n", var_export($a->model()['logged_in'], true), $a->model()['error']);

    // 2) 填表（真实键盘路径：聚焦 + 逐字输入，而非整体覆盖）
    $a->input('user-in', 'alice');
    $a->input('pass-in', 'secret');
    $a->setToggle('show-toggle', true);   // 显示密码
    $a->setChecked('remember-box', true); // 记住我
    printf("[filled] show=%s remember=%s\n", var_export($a->model()['show'], true), var_export($a->model()['remember'], true));

    // 3) 真实登录
    $a->clickById('login-btn');
    $m = $a->model();
    printf("[login]  logged_in=%s user=%s\n", var_export($m['logged_in'], true), $m['user']);

    $backend->quit();
}
