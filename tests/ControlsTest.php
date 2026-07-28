<?php
declare(strict_types=1);

namespace Yangweijie\Ui3\Tests;

use Yangweijie\Ui3\App;
use Yangweijie\Ui3\Automation\Automation;
use Yangweijie\Ui3\Automation\Snapshot;
use Yangweijie\Ui3\Backends\Canvas;
use Yangweijie\Ui3\Element;
use Yangweijie\Ui3\Ui;

function controlsApp(): App
{
    return new App(
        init: fn (): array => ['s' => 0, 'sel' => 0],
        update: function (array $m, string $msg, mixed $payload = null): array {
            return match ($msg) {
                'setS' => [...$m, 's' => (int) $payload],
                'setSel' => [...$m, 'sel' => (int) $payload],
                default => $m,
            };
        },
        view: function (array $m): Element {
            return Ui::window('Controls', [
                Ui::column([
                    Ui::label('WWWWWWWWWW', 'wide'),
                    Ui::label('iiiiiiiiii', 'narrow'),
                    Ui::slider(0, 100, $m['s'], 'setS', 's'),
                    Ui::select(['One', 'Two', 'Three'], $m['sel'], 'setSel', 'sel'),
                ]),
            ], width: 320, height: 240);
        },
    );
}

test('text width uses real cairo_text_extents, not a fixed estimate', function () {
    $auto = new Automation(controlsApp(), new Canvas(headless: true));
    $auto->start();
    $wide = Snapshot::findById($auto->snapshot(), 'wide');
    $narrow = Snapshot::findById($auto->snapshot(), 'narrow');
    expect($wide)->not->toBeNull();
    expect($narrow)->not->toBeNull();
    // Wide glyphs ("W") must out-measure narrow glyphs ("i"); the old
    // mb_strlen*0.55 estimate gives both the same width and would fail here.
    expect($wide['w'])->toBeGreaterThan($narrow['w']);
});

test('slider drag sets value via pointer down/move/up', function () {
    $auto = new Automation(controlsApp(), new Canvas(headless: true));
    $auto->start();
    $auto->dragSlider('s', 75);
    $s = Snapshot::findById($auto->snapshot(), 's');
    expect($s['state']['value'])->toBe(75);
});

test('dropdown expands, picks an option, then collapses', function () {
    $auto = new Automation(controlsApp(), new Canvas(headless: true));
    $auto->start();
    $auto->toggleSelect('sel');
    expect($auto->backend()->isExpanded('sel'))->toBeTrue();
    $auto->clickSelectOption('sel', 2);
    $sel = Snapshot::findById($auto->snapshot(), 'sel');
    expect($sel['state']['selected'])->toBe(2);
    expect($auto->backend()->isExpanded('sel'))->toBeFalse();
});
