<?php
declare(strict_types=1);

use Yangweijie\Ui3\{App, Ui, Element};
use Yangweijie\Ui3\Backends\Canvas;
use Yangweijie\Ui3\Automation\{Automation, Snapshot};

function formApp(): App
{
    return new App(
        init: fn(): array => [
            'name' => '', 'agree' => false, 'level' => 5,
            'choice' => 0, 'picked' => -1, 'size' => 'S',
        ],
        update: function (array $model, string $msg, mixed $payload = null): array {
            return match ($msg) {
                'name'   => [...$model, 'name' => (string) $payload],
                'agree'  => [...$model, 'agree' => (bool) $payload],
                'level'  => [...$model, 'level' => (int) $payload],
                'choice' => [...$model, 'choice' => (int) $payload],
                'pick'   => [...$model, 'picked' => (int) $payload],
                'size'   => [...$model, 'size' => (string) $payload],
                default  => $model,
            };
        },
        view: function (array $model): Element {
            return Ui::window('Form', [
                Ui::heading('Profile', 'h'),
                Ui::input($model['name'], 'name', 'name', 'name-input'),
                Ui::checkbox('Agree', $model['agree'], 'agree', 'agree-box'),
                Ui::radio('Small', 'size', $model['size'] === 'S', 'size-s', 'radio-s'),
                Ui::radio('Large', 'size', $model['size'] === 'L', 'size-l', 'radio-l'),
                Ui::slider(0, 10, $model['level'], 'level', 'level-slider'),
                Ui::progress(0.4, 'prog'),
                Ui::select(['Low', 'Mid', 'High'], $model['choice'], 'choice', 'choice-select'),
                Ui::list(['Apple', 'Banana', 'Cherry'], $model['picked'], 'pick', 'pick-list'),
                Ui::divider(),
                Ui::panel('Group', [Ui::label('inside', 'inside-label')], 'grp'),
            ]);
        },
    );
}

test('snapshot exposes every widget role, handler and value state', function () {
    $auto = (new Automation(formApp(), new Canvas(headless: true)))->start();
    $snap = $auto->snapshot();

    $input = Snapshot::findById($snap, 'name-input');
    expect($input['role'])->toBe('input');
    expect($input['handler'])->toBe('name');
    expect($input['state']['value'])->toBe('');

    $box = Snapshot::findById($snap, 'agree-box');
    expect($box['role'])->toBe('checkbox');
    expect($box['state']['checked'])->toBeFalse();

    $slider = Snapshot::findById($snap, 'level-slider');
    expect($slider['role'])->toBe('slider');
    expect($slider['state'])->toBe(['min' => 0, 'max' => 10, 'value' => 5]);

    $sel = Snapshot::findById($snap, 'choice-select');
    expect($sel['role'])->toBe('select');
    expect($sel['state']['options'])->toBe(['Low', 'Mid', 'High']);
    expect($sel['state']['selected'])->toBe(0);

    $list = Snapshot::findById($snap, 'pick-list');
    expect($list['role'])->toBe('list');
    expect($list['state']['items'])->toBe(['Apple', 'Banana', 'Cherry']);
    expect($list['state']['selected'])->toBe(-1);

    expect(Snapshot::findById($snap, 'prog')['state']['value'])->toBe(0.4);
    expect(Snapshot::findById($snap, 'grp')['role'])->toBe('panel');
    expect(Snapshot::findById($snap, 'inside-label')['name'])->toBe('inside');
    expect(Snapshot::findByRole($snap, 'radio'))->toHaveCount(2);
});

test('automation drives value widgets through payloads', function () {
    $auto = (new Automation(formApp(), new Canvas(headless: true)))->start();

    $auto->input('name-input', 'Ada');
    expect($auto->model()['name'])->toBe('Ada');
    expect(Snapshot::findById($auto->snapshot(), 'name-input')['state']['value'])->toBe('Ada');

    $auto->setChecked('agree-box', true);
    expect($auto->model()['agree'])->toBeTrue();
    expect(Snapshot::findById($auto->snapshot(), 'agree-box')['state']['checked'])->toBeTrue();

    $auto->slideTo('level-slider', 8);
    expect($auto->model()['level'])->toBe(8);

    $auto->selectOption('choice-select', 2);
    expect($auto->model()['choice'])->toBe(2);
    expect(Snapshot::findById($auto->snapshot(), 'choice-select')['state']['selected'])->toBe(2);

    $auto->selectListItem('pick-list', 1);
    expect($auto->model()['picked'])->toBe(1);
    expect(Snapshot::findById($auto->snapshot(), 'pick-list')['state']['selected'])->toBe(1);
});

test('driving a mismatched widget type raises a clear error', function () {
    $auto = (new Automation(formApp(), new Canvas(headless: true)))->start();
    expect(fn() => $auto->setChecked('name-input', true))
        ->toThrow(\RuntimeException::class);
    expect(fn() => $auto->slideTo('agree-box', 3))
        ->toThrow(\RuntimeException::class);
});
