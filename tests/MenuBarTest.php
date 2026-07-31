<?php
declare(strict_types=1);

use Yangweijie\Ui3\{App, Ui, Element};
use Yangweijie\Ui3\Backends\Canvas;
use Yangweijie\Ui3\Automation\Automation;

/**
 * P-Native P1 — native menu bar.
 *
 * Headless: the native NSMenu / HMENU build is not exercised; instead the
 * window menu is recorded (lastMenu) and menu clicks are simulated via
 * click_menu -> UI3_EVENT_MENU -> App dispatch — the exact path a native menu
 * click uses. Native rendering (Cocoa setMainMenu / Win32 SetMenu) is
 * implemented per platform; X11 is a documented gap (raw-X11 backend).
 */
function menuApp(): App
{
    return new App(
        init: fn(): array => ['action' => null],
        update: function (array $m, string $msg, mixed $payload = null): array {
            if (in_array($msg, ['open', 'save', 'quit', 'cut'], true)) {
                $m['action'] = $msg;
            }
            return $m;
        },
        view: fn(array $m): Element => Ui::window('Menu', [
            Ui::label('hi'),
        ], width: 320, height: 240, menu: [
            Ui::appMenu('File', [
                Ui::appMenuItem('Open', 'open', 'Cmd+O'),
                Ui::appMenuItem('Save', 'save'),
                Ui::appMenuSeparator(),
                Ui::appMenuItem('Quit', 'quit', 'Cmd+Q'),
            ]),
            Ui::appMenu('Edit', [
                Ui::appMenuItem('Cut', 'cut', 'Cmd+X'),
            ]),
        ]),
    );
}

test('the window menu is encoded and recorded on the host', function () {
    $auto = (new Automation(menuApp(), new Canvas(headless: true)))->start();
    $c = $auto->backend();

    $m = $c->lastMenu();
    expect($m)->not->toBeNull();
    expect($m)->toContain("File\n");
    expect($m)->toContain("\tOpen\topen\tCmd+O\n");
    expect($m)->toContain("\t-\n");
    expect($m)->toContain("Edit\n");
    expect($m)->toContain("\tCut\tcut\tCmd+X\n");
});

test('clicking a menu item dispatches its message to the App', function () {
    $auto = (new Automation(menuApp(), new Canvas(headless: true)))->start();

    $auto->clickMenu('open');
    expect($auto->model()['action'])->toBe('open');

    $auto->clickMenu('cut');
    expect($auto->model()['action'])->toBe('cut');
});

test('a window without a menu records nothing and ignores clicks', function () {
    $app = new App(
        init: fn(): array => ['action' => null],
        update: fn(array $m, string $msg, mixed $payload = null): array => $m,
        view: fn(array $m): Element => Ui::window('NoMenu', [Ui::label('x')]),
    );
    $auto = (new Automation($app, new Canvas(headless: true)))->start();

    expect($auto->backend()->lastMenu())->toBeNull();
    $auto->clickMenu('open');   // no menu -> no handler -> no crash
    expect($auto->model()['action'])->toBeNull();
});
