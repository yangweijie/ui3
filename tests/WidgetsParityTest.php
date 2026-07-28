<?php

declare(strict_types=1);

use Yangweijie\Ui3\Automation\Snapshot;
use Yangweijie\Ui3\Canvas\Layout;
use Yangweijie\Ui3\Ui;

test('every previously-missing widget renders and is observable in the snapshot', function () {
    $root = Ui::window('parity', [
        Ui::tabs([
            Ui::tabPage('One', [Ui::button('p1', null, 'tab-btn')]),
            Ui::tabPage('Two', [Ui::label('two')]),
        ], 0, null, 'tabs1'),
        Ui::card('Card', [Ui::label('c')], 'card1'),
        Ui::alert('error', 'boom', 'alert1'),
        Ui::accordion([
            ['title' => 'S1', 'children' => [Ui::label('a1')], 'expanded' => true],
            ['title' => 'S2', 'children' => []],
        ], 'acc1'),
        Ui::dialog('Dlg', [Ui::label('d')], [], 'dlg1'),
        Ui::sheet('Sh', [Ui::label('s')], 'sheet1'),
        Ui::drawer('left', [Ui::label('dr')], 'drw1'),
        Ui::scrollView([Ui::label('sc')], 0, 'scr1'),
        Ui::table(['A', 'B'], [['1', '2'], ['3', '4']], 'tbl1'),
        Ui::comboBox(['x', 'y'], 0, null, 'cmb1'),
        Ui::dropDown(['m', 'n'], null, 'dd1'),
        Ui::menu([Ui::menuItem('Item', null, 'mi1')], 'menu1'),
        Ui::tree([Ui::treeNode('Root', [Ui::treeNode('Child', [], true, 'tn-child')], true, 'tn-root')], 'tree1'),
        Ui::chart([1, 2, 3], 'bar', 'chart1'),
        Ui::tooltip('tip', 'tip1'),
        Ui::badge('9', 'badge1'),
        Ui::avatar('AB', 'av1'),
        Ui::skeleton(3, 'sk1'),
        Ui::spinner('spin1'),
        Ui::switchControl(true, null, 'sw1'),
        Ui::richText([['text' => 'hi', 'bold' => true]], 'rt1'),
        Ui::breadcrumb(['Home', 'X'], 'bc1'),
        Ui::pagination(2, 5, null, 'pg1'),
        Ui::buttonGroup([Ui::button('A', null, 'bg-a'), Ui::button('B', null, 'bg-b')], 'bg1'),
    ], 720, 1500);

    // layout must not throw for any of the new widgets
    $nodes = Layout::compute($root);
    expect($nodes)->not->toBeEmpty();

    $snap = Snapshot::capture($root, 720, 1500, $nodes);
    $roles = array_map(fn($w) => $w['role'], $snap['widgets']);

    $expected = [
        'tabs', 'tab_page', 'card', 'alert', 'accordion', 'dialog', 'sheet',
        'drawer', 'scroll', 'table', 'combobox', 'dropdown', 'menu', 'menu_item',
        'tree', 'tree_node', 'chart', 'tooltip', 'badge', 'avatar', 'skeleton',
        'spinner', 'switch', 'richtext', 'breadcrumb', 'pagination', 'button_group',
    ];
    foreach ($expected as $role) {
        expect($roles)->toContain($role);
    }

    // the selected tab's content is laid out
    expect(Snapshot::findById($snap, 'tab-btn'))->not->toBeNull();
});
