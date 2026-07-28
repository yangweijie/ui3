<?php
declare(strict_types=1);

use Yangweijie\Ui3\{App, Ui, Element};

// Every component exposed by the native SDK (packages/native-sdk native-sdk.d.ts
// NativeSdkViewKind) must be representable and drivable on the PHP side.
test('all native SDK components are implemented and drivable', function () {
    $model = [
        'tog' => 0,
        'seg' => 0,
        'search' => '',
        'icon' => 0,
        'picked' => -1,
        'split' => 50,
    ];

    $update = function (array $m, string $msg, mixed $p = null): array {
        return array_merge($m, match ($msg) {
            'toggle' => ['tog' => $p ? 1 : 0],
            'seg' => ['seg' => (int) $p],
            'search' => ['search' => (string) $p],
            'icon' => ['icon' => 1],
            'pick' => ['picked' => (int) $p],
            'split' => ['split' => (int) $p],
            default => [],
        });
    };

    $view = function (array $m): Element {
        return Ui::window('Native Components', [
            Ui::toolbar([
                Ui::iconButton('⚙', 'Settings', 'icon', 'tb-settings'),
                Ui::iconButton('+', 'Add', 'icon', 'tb-add'),
            ], 'the-toolbar'),
            Ui::titlebarAccessory([Ui::label('Title')], 'the-titlebar'),
            Ui::sidebar([
                Ui::button('Home', 'home', 'sb-home'),
                Ui::button('Docs', 'docs', 'sb-docs'),
            ], 'the-sidebar'),
            Ui::column([
                Ui::toggle('Enable feature', (bool) $m['tog'], 'toggle', 'the-toggle'),
                Ui::segmented(['One', 'Two', 'Three'], $m['seg'], 'seg', 'the-seg'),
                Ui::searchField($m['search'], 'Search…', 'search', 'the-search'),
                Ui::iconButton('★', 'Star', 'icon', 'the-icon'),
                Ui::stack([
                    Ui::label('Left'),
                    Ui::label('Right'),
                ], 'horizontal', 'the-stack'),
                Ui::list([
                    Ui::listItem('📄', 'Document', 'A text file', 'li-doc'),
                    Ui::listItem('🖼', 'Image', 'A picture', 'li-img'),
                ], -1, 'pick', 'the-list'),
                Ui::split([
                    Ui::label('Pane A'),
                    Ui::label('Pane B'),
                ], 'horizontal', 0.5, 'split', 'the-split'),
                Ui::webview('https://example.com', 'the-web'),
                Ui::gpuSurface(200, 120, 'the-gpu'),
            ]),
            Ui::statusbar([Ui::label('Ready')], 'the-status'),
        ], 520, 420);
    };

    $app = new App($model, $update, $view);
    $a = new \Yangweijie\Ui3\Automation\Automation($app, new \Yangweijie\Ui3\Backends\Canvas(headless: true));
    $a->start();

    $snap = $a->snapshot();
    $roles = array_column($snap['widgets'], 'role');

    // Every native SDK kind is present in the tree.
    foreach (['toolbar', 'titlebar', 'sidebar', 'statusbar', 'toggle', 'segmented',
               'search', 'iconbutton', 'stack', 'list_item', 'split', 'webview', 'gpusurface'] as $kind) {
        expect($roles)->toContain($kind);
    }

    // toggle drives onChange
    $a->clickById('the-toggle');
    expect($a->model()['tog'])->toBe(1);

    // segmented drives onChange with the chosen index
    $a->setSegmented('the-seg', 2);
    expect($a->model()['seg'])->toBe(2);

    // icon button drives onClick
    $a->clickById('the-icon');
    expect($a->model()['icon'])->toBe(1);

    // search field drives onInput
    $a->input('the-search', 'hello');
    expect($a->model()['search'])->toBe('hello');

    // list_item row selection drives onSelect
    $a->selectListItem('the-list', 1);
    expect($a->model()['picked'])->toBe(1);

    // split divider drives onChange with a 0..100 position
    $a->setSplit('the-split', 30);
    expect($a->model()['split'])->toBe(30);

});

test('list_item rows are selectable by their own id', function () {
    $app = new App(
        ['picked' => -1],
        fn (array $m, string $msg, mixed $p = null) => array_merge($m, $msg === 'pick' ? ['picked' => (int) $p] : []),
        fn (array $m) => Ui::window('L', [
            Ui::list([
                Ui::listItem('📄', 'Document', 'A file', 'row-0'),
                Ui::listItem('🖼', 'Image', 'A pic', 'row-1'),
            ], -1, 'pick', 'the-list'),
        ]),
    );
    $a = new \Yangweijie\Ui3\Automation\Automation($app, new \Yangweijie\Ui3\Backends\Canvas(headless: true));
    $a->start();
    $a->clickById('row-1');
    expect($a->model()['picked'])->toBe(1);
});
