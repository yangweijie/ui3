<?php
declare(strict_types=1);

use Yangweijie\Ui3\App;
use Yangweijie\Ui3\Ui;
use Yangweijie\Ui3\Backends\Canvas;
use Yangweijie\Ui3\Automation\Automation;
use Yangweijie\Ui3\Automation\Snapshot;

const SP_LEFT = 1;
const SP_RIGHT = 2;

function spApp(string $value = 'brwn', bool $spellcheck = true): App
{
    return new App(
        init: fn (): array => ['v' => $value],
        update: fn (array $m, string $msg, mixed $p = null): array =>
            $msg === 'v' ? [...$m, 'v' => (string)$p] : $m,
        view: fn (array $m): \Yangweijie\Ui3\Element =>
            Ui::window('Edit', [Ui::input($m['v'], 'value', 'v', 'sp-input', null, $spellcheck)], width: 300, height: 160),
    );
}

function spPixels(Canvas $c): string
{
    return md5(serialize($c->offscreenPixels()['px']));
}

function spFieldRect(Automation $auto): array
{
    $snap = $auto->snapshot();
    expect($snap)->not->toBeNull();
    return Snapshot::findById($snap, 'sp-input');
}

function spRightClick(Automation $auto, int $x, int $y): void
{
    $auto->backend()->injectPointer($x, $y, true, SP_RIGHT);
    $auto->backend()->injectPointer($x, $y, false, SP_RIGHT);
    $auto->step();
}

function spClickRow(Automation $auto, int $index): void
{
    $rect = $auto->backend()->contextMenuItemRect('sp-input', $index);
    expect($rect)->not->toBeNull();
    $cx = $rect[0] + (int)($rect[2] / 2);
    $cy = $rect[1] + (int)($rect[3] / 2);
    $auto->backend()->injectPointer($cx, $cy, true, SP_LEFT);
    $auto->backend()->injectPointer($cx, $cy, false, SP_LEFT);
    $auto->step();
}

test('canvas draws squiggles for misspelled text when spellcheck enabled', function () {
    $c = new Canvas(headless: true);
    $c->mount(Ui::window('F', [Ui::input('brwn', 'value', 'v', 'field-1', null, true)]), static fn() => null);
    $with = spPixels($c);

    $c->update(Ui::window('F', [Ui::input('brwn', 'value', 'v', 'field-1', null, false)]));
    $without = spPixels($c);

    expect($with)->not->toBe($without);
});

test('canvas draws no squiggles for correctly spelled text', function () {
    $c = new Canvas(headless: true);
    $c->mount(Ui::window('F', [Ui::input('brown', 'value', 'v', 'field-1', null, true)]), static fn() => null);
    $with = spPixels($c);

    $c->update(Ui::window('F', [Ui::input('brown', 'value', 'v', 'field-1', null, false)]));
    $without = spPixels($c);

    expect($with)->toBe($without);
});

test('right-click on misspelled word shows suggestions above edit menu', function () {
    $auto = (new Automation(spApp(), new Canvas(headless: true)))->start();
    $field = spFieldRect($auto);

    spRightClick($auto, $field['x'] + 10, $field['y'] + 8);

    expect($auto->backend()->isContextMenuOpen('sp-input'))->toBeTrue();
    $items = $auto->backend()->contextMenuItems('sp-input');
    $titles = array_column($items, 'title');

    expect($items[0]['title'])->toBe('brown');
    expect($items[0]['msg'])->toBe('spell:replace:brwn:brown');
    expect($titles)->toContain('Add to dictionary');
    expect($titles)->toContain('Ignore');
    expect(end($titles))->toBe('Select All');
});

test('selecting a suggestion replaces the misspelled word', function () {
    $auto = (new Automation(spApp(), new Canvas(headless: true)))->start();
    $field = spFieldRect($auto);

    spRightClick($auto, $field['x'] + 10, $field['y'] + 8);
    spClickRow($auto, 0);

    expect($auto->backend()->isContextMenuOpen('sp-input'))->toBeFalse();

    // Text is now 'brown' — a second right-click shows only the edit menu.
    spRightClick($auto, $field['x'] + 10, $field['y'] + 8);
    $items = $auto->backend()->contextMenuItems('sp-input');
    $titles = array_column($items, 'title');
    expect($titles[0])->toBe('Clipboard');
    expect($titles)->not->toContain('Ignore');
});

test('adding a word to the dictionary suppresses its squiggles', function () {
    $auto = (new Automation(spApp(), new Canvas(headless: true)))->start();
    $field = spFieldRect($auto);

    spRightClick($auto, $field['x'] + 10, $field['y'] + 8);
    $titles = array_column($auto->backend()->contextMenuItems('sp-input'), 'title');
    $idx = array_search('Add to dictionary', $titles, true);
    expect($idx)->not->toBeFalse();

    spClickRow($auto, $idx);

    // 'brwn' is now known — a second right-click shows only the edit menu.
    spRightClick($auto, $field['x'] + 10, $field['y'] + 8);
    $items = $auto->backend()->contextMenuItems('sp-input');
    $titles2 = array_column($items, 'title');
    expect($titles2[0])->toBe('Clipboard');
    expect($titles2)->not->toContain('Ignore');
});
