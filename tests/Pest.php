<?php
declare(strict_types=1);

use Yangweijie\Ui3\{App, Element, Ui};

// Shared test helper: collect label/button leaves from a view tree in pre-order.
function ui3_collect_leaves(Element $el): array
{
    if ($el->type === 'window' || $el->type === 'column' || $el->type === 'row') {
        $out = [];
        foreach ($el->children as $child) {
            $out = array_merge($out, ui3_collect_leaves($child));
        }
        return $out;
    }
    return [$el];
}

// Shared test fixture: a tiny editable-text app (an input bound to model key 'v').
// Used by text-editing and automation-server tests so the widget ids ('v-input',
// 'inc-btn') and model keys ('v', 'n') stay in one place.
function editApp(): App
{
    return new App(
        init: fn(): array => ['v' => ''],
        update: function (array $m, string $msg, mixed $payload = null): array {
            return match ($msg) {
                'v' => [...$m, 'v' => (string) $payload],
                default => $m,
            };
        },
        view: function (array $m): Element {
            return Ui::window('Edit', [
                Ui::input($m['v'], 'value', 'v', 'v-input'),
            ], width: 300, height: 160);
        },
    );
}
