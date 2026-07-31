<?php
declare(strict_types=1);

use Yangweijie\Ui3\App;
use Yangweijie\Ui3\Automation\Automation;
use Yangweijie\Ui3\Automation\Snapshot;
use Yangweijie\Ui3\Backends\Canvas;
use Yangweijie\Ui3\Element;
use Yangweijie\Ui3\Ui;

function rtApp(): App {
    return new App(
        init: fn (): array => [],
        update: fn (array $m, string $msg, mixed $payload = null): array => $m,
        view: fn (array $m): Element => Ui::window('W', [
            new Element('label', ['text' => 'T1', 'id' => 'l1', 'bold' => true]),
            new Element('label', ['text' => 'T2', 'id' => 'l2', 'italic' => true]),
            new Element('label', ['text' => 'T3', 'id' => 'l3', 'underline' => true]),
            new Element('label', ['text' => 'T4', 'id' => 'l4', 'fontSize' => 24]),
            new Element('label', ['text' => 'T5', 'id' => 'l5', 'bold' => true, 'italic' => true, 'underline' => true, 'fontSize' => 18]),
        ], width: 500, height: 200),
    );
}

test("bold label renders without error and preserves text", function () {
    $a = new Automation(rtApp(), new Canvas(headless: true));
    $a->start();
    $snap = $a->snapshot();
    expect(Snapshot::findById($snap, 'l1')['name'])->toBe('T1');
});

test("italic label renders without error and preserves text", function () {
    $a = new Automation(rtApp(), new Canvas(headless: true));
    $a->start();
    $snap = $a->snapshot();
    expect(Snapshot::findById($snap, 'l2')['name'])->toBe('T2');
});

test("underline label renders without error and preserves text", function () {
    $a = new Automation(rtApp(), new Canvas(headless: true));
    $a->start();
    $snap = $a->snapshot();
    expect(Snapshot::findById($snap, 'l3')['name'])->toBe('T3');
});

test("fontSize prop is rendered at a larger size without error", function () {
    $a = new Automation(rtApp(), new Canvas(headless: true));
    $a->start();
    $snap = $a->snapshot();
    expect(Snapshot::findById($snap, 'l4')['name'])->toBe('T4');
});

test("combined bold + italic + underline + fontSize renders without error", function () {
    $a = new Automation(rtApp(), new Canvas(headless: true));
    $a->start();
    $snap = $a->snapshot();
    expect(Snapshot::findById($snap, 'l5')['name'])->toBe('T5');
});
