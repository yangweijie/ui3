<?php

declare(strict_types=1);

use Yangweijie\Ui3\App;
use Yangweijie\Ui3\Assets;
use Yangweijie\Ui3\Extensions;
use Yangweijie\Ui3\Security\Capabilities;
use Yangweijie\Ui3\Security\SecurityException;
use Yangweijie\Ui3\Ui;

/**
 * Phase 10 (Direction 10): other systems — extensions, security, assets.
 *
 * Covers the extension hook bus, a capability/security gate, and an assets
 * pipeline, plus wiring the extension bus into the App lifecycle.
 */
test('Extensions registry triggers hooks at lifecycle points', function () {
    $ext = new Extensions();
    $seen = [];
    $ext->register('beforeRender', static function ($model) use (&$seen) {
        $seen[] = 'before:' . $model['n'];
    });
    $ext->register('beforeRender', static function ($model) use (&$seen) {
        $seen[] = 'before2:' . $model['n'];
    });
    $ext->trigger('beforeRender', ['n' => 1]);
    expect($seen)->toBe(['before:1', 'before2:1']);
    expect($ext->has('afterUpdate'))->toBeFalse();
});

test('Capabilities gate sensitive operations and fail closed', function () {
    $caps = new Capabilities();
    expect($caps->allows('fs.write'))->toBeFalse();
    $caps->grant('fs.write');
    expect($caps->allows('fs.write'))->toBeTrue();
    $caps->demand('fs.write'); // ok

    $caps->deny('fs.write');
    expect($caps->allows('fs.write'))->toBeFalse();
    expect(static fn() => $caps->demand('fs.write'))->toThrow(SecurityException::class);
});

test('Assets pipeline resolves names to base-prefixed URLs', function () {
    $assets = new Assets('/static');
    $assets->register('icon:save', 'icons/save.png');
    expect($assets->url('icon:save'))->toBe('/static/icons/save.png');
    expect($assets->has('icon:save'))->toBeTrue();
    expect($assets->url('missing'))->toBeNull();

    // absolute / remote paths are not prefixed
    $assets2 = new Assets('/static');
    $assets2->register('logo', 'https://example.com/logo.png');
    expect($assets2->url('logo'))->toBe('https://example.com/logo.png');
});

test('App fires extension hooks around render and update', function () {
    $log = [];
    $app = new App(
        fn() => ['n' => 0],
        static fn($m, $msg) => $m,
        static fn($m) => Ui::window('main', [Ui::label('n=' . $m['n'])], 320, 240),
    );
    $app->extend('beforeRender', static function ($model) use (&$log) {
        $log[] = 'render:' . $model['n'];
    });
    $app->extend('afterUpdate', static function ($model) use (&$log) {
        $log[] = 'update:' . $model['n'];
    });

    $app->start();
    $app->render();
    $app->dispatch('inc');
    expect($log)->toBe(['render:0', 'update:0']);
});
