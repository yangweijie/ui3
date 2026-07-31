<?php
declare(strict_types=1);

use Yangweijie\Ui3\{App, Ui, Element};
use Yangweijie\Ui3\Backends\Canvas;
use Yangweijie\Ui3\Automation\Automation;

/**
 * P-Native P1 — OS-level gestures (pinch / rotate / swipe / pan).
 *
 * Headless: a GESTURE event is injected through the SAME ui3_host_inject_gesture
 * -> event_cb -> onGesture path a native trackpad gesture uses, so these tests
 * verify gesture hit-testing -> element onGesture -> App dispatch. Native
 * delivery: Cocoa (magnify/rotate/swipe/scrollWheel momentum), Win32 (WM_GESTURE
 * API: zoom/rotate/pan/tap), X11 (XI2 TouchBegin/Update/End + pinch/pan/rotate
 * math). All three platforms covered.
 */
function gestureApp(): App
{
    return new App(
        init: fn(): array => ['g' => null],
        update: function (array $m, string $msg, mixed $payload = null): array {
            if (in_array($msg, ['pinch', 'rotate', 'swipe', 'pan'], true)) {
                $m['g'] = $msg;
            }
            return $m;
        },
        view: fn(array $m): Element => Ui::window('Gestures', [
            Ui::gesture(Ui::label('pinch me here please', 'g-target'), 'pinch', 'pinch'),
            Ui::gesture(Ui::label('rotate me here please', 'r-target'), 'rotate', 'rotate'),
            Ui::gesture(Ui::label('pan me here please', 'p-target'), 'pan', 'pan'),
        ], width: 320, height: 240),
    );
}

test('a pinch over a pinch target fires its onGesture', function () {
    $auto = (new Automation(gestureApp(), new Canvas(headless: true)))->start();
    $w = \Yangweijie\Ui3\Automation\Snapshot::findById($auto->snapshot(), 'g-target');

    $auto->gesture(0, $w['x'] + $w['w'] / 2, $w['y'] + $w['h'] / 2, '0.2');

    expect($auto->model()['g'])->toBe('pinch');
});

test('a rotate over a rotate target fires its onGesture', function () {
    $auto = (new Automation(gestureApp(), new Canvas(headless: true)))->start();
    $w = \Yangweijie\Ui3\Automation\Snapshot::findById($auto->snapshot(), 'r-target');

    $auto->gesture(1, $w['x'] + $w['w'] / 2, $w['y'] + $w['h'] / 2, '12.5');

    expect($auto->model()['g'])->toBe('rotate');
});

test('a gesture over empty space does nothing', function () {
    $auto = (new Automation(gestureApp(), new Canvas(headless: true)))->start();

    $auto->gesture(0, 5.0, 5.0, '0.2');   // top-left corner, no target

    expect($auto->model()['g'])->toBeNull();
});

test('a pan over a pan target fires its onGesture', function () {
    $auto = (new Automation(gestureApp(), new Canvas(headless: true)))->start();
    $w = \Yangweijie\Ui3\Automation\Snapshot::findById($auto->snapshot(), 'p-target');

    $auto->gesture(3, $w['x'] + $w['w'] / 2, $w['y'] + $w['h'] / 2, '2.0,-1.5');

    expect($auto->model()['g'])->toBe('pan');
});
