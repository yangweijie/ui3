<?php
declare(strict_types=1);

use Yangweijie\Ui3\{App, Ui, Element};
use Yangweijie\Ui3\Backends\Canvas;
use Yangweijie\Ui3\Automation\{Automation, Snapshot};

function eventLoopApp(): App
{
    return new App(
        init: fn(): array => ['count' => 0],
        update: function (array $model, string $msg): array {
            return match ($msg) {
                'inc' => ['count' => $model['count'] + 1],
                'dec' => ['count' => $model['count'] - 1],
                default => $model,
            };
        },
        view: function (array $model): Element {
            return Ui::window('Counter', [
                Ui::label("Count: {$model['count']}", 'count-label'),
                Ui::row([
                    Ui::button('+', 'inc', 'inc-btn'),
                    Ui::button('-', 'dec', 'dec-btn'),
                ]),
            ], width: 300, height: 200);
        },
    );
}

/**
 * #2 — real window event loop. Every pointer activation must travel the same
 * path a native window uses: injectPointer -> host event_cb -> onEvent ->
 * hitTest (real layout coords) -> fire -> dispatch -> redraw. These tests
 * drive that path headlessly so the real window routing is verifiable in CI.
 */
test('real pointer click at laid-out coordinates drives the model via the event loop', function () {
    $auto = (new Automation(eventLoopApp(), new Canvas(headless: true)))->start();

    // Activate the "+" button at its real laid-out centre — exactly what a
    // native mouse-down does. If this routed through direct dispatch the
    // coordinates would be irrelevant; here they hit-test the button.
    $inc = Snapshot::findById($auto->snapshot(), 'inc-btn');
    $auto->clickAt($inc['x'] + $inc['w'] / 2, $inc['y'] + $inc['h'] / 2);

    expect($auto->model()['count'])->toBe(1);

    // The re-rendered snapshot reflects the new model (request_redraw -> paint).
    $lbl = Snapshot::findById($auto->snapshot(), 'count-label');
    expect($lbl['name'])->toBe('Count: 1');
});

test('pointer click on empty space is filtered by hit-testing (no-op)', function () {
    $auto = (new Automation(eventLoopApp(), new Canvas(headless: true)))->start();

    // (5,5) is inside the window but on no widget. A real pointer event there
    // hit-tests to the window node only, whose fire() is a no-op — proving the
    // click went through hit-testing and not a coordinate-blind dispatch.
    $auto->clickAt(5, 5);

    expect($auto->model()['count'])->toBe(0);
});

test('clickById resolves real coordinates and activates through onEvent', function () {
    $auto = (new Automation(eventLoopApp(), new Canvas(headless: true)))->start();

    $auto->clickById('inc-btn');
    $auto->clickById('inc-btn');
    $auto->clickText('-'); // "-" is the dec button's text

    expect($auto->model()['count'])->toBe(1);
});
