<?php

declare(strict_types=1);

use Yangweijie\Ui3\App;
use Yangweijie\Ui3\Ui;
use Yangweijie\Ui3\Windows;

/**
 * Phase 8 (Direction 8): multi-window (window_state management).
 *
 * Covers the window lifecycle (open/close/focus, active tracking) via the
 * Windows manager and its App integration.
 */
test('Windows manager tracks open/close/focus and active window', function () {
    $wm = new Windows();
    expect($wm->count())->toBe(0);
    expect($wm->active())->toBeNull();

    $wm->open('main', 'Main', 400, 300);
    $wm->open('tools', 'Tools', 200, 150);
    expect($wm->count())->toBe(2);
    expect($wm->active())->toBe('main'); // first opened becomes active

    $wm->focus('tools');
    expect($wm->active())->toBe('tools');

    $wm->close('tools');
    expect($wm->isOpen('tools'))->toBeFalse();
    expect($wm->active())->toBe('main'); // active falls back to remaining open window

    $wm->open('tools', 'Tools', 200, 150);
    $wm->focus('tools');
    $wm->close('main');
    expect($wm->active())->toBe('tools');
    expect($wm->list())->toHaveCount(1);
});

test('App exposes a window manager and lifecycle methods', function () {
    $app = new App(
        fn() => ['n' => 0],
        fn($m, $msg) => $m,
        fn($m) => Ui::window('main', [Ui::label('x')], 320, 240),
    );

    $app->openWindow('prefs', 'Preferences', 320, 240);
    $app->openWindow('about', 'About');
    expect($app->windows()->count())->toBe(2);
    expect($app->activeWindow())->toBe('prefs');

    $app->focusWindow('about');
    expect($app->activeWindow())->toBe('about');

    $app->closeWindow('about');
    expect($app->windows()->isOpen('about'))->toBeFalse();
    expect($app->activeWindow())->toBe('prefs');
});
