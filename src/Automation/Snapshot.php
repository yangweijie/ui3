<?php
declare(strict_types=1);

namespace Yangweijie\Ui3\Automation;

use Yangweijie\Ui3\Element;
use Yangweijie\Ui3\Canvas\Node;

/**
 * Automation snapshot (mirrors native's automationSnapshot): a serializable,
 * coordinate-free view of the live UI tree. Each widget carries a stable
 * id (when declared), its role, display name, enabled state, parent chain
 * and layout bounds — so automation can find and assert on controls without
 * ever touching screen pixels.
 *
 * Bounds come from the Canvas backend's real laid-out geometry when supplied;
 * otherwise a lightweight internal estimate is used. Either way, controls are
 * addressed by id — the bounds only describe where the element was drawn.
 *
 * @return array{title:string,width:int,height:int,widgets:list<array<string,mixed>>}
 */
final class Snapshot
{
    private const ROW = 32;

    /**
     * @param int $width  Hint for the window width (used only if the root has no width prop).
     * @param int $height Hint for the window height.
     * @param list<Node> $nodes Real laid-out nodes; when given, bounds come from them.
     * @return array{title:string,width:int,height:int,widgets:list<array<string,mixed>>}
     */
    public static function capture(Element $root, int $width = 320, int $height = 240, array $nodes = []): array
    {
        $title = (string) $root->prop('title', 'App');
        $width = (int) $root->prop('width', $width);
        $height = (int) $root->prop('height', $height);

        $boxes = [];
        if ($nodes !== []) {
            foreach ($nodes as $n) {
                $id = $n->el->prop('id');
                $key = is_string($id) ? $id : spl_object_id($n->el);
                $boxes[$key] = ['x' => $n->x, 'y' => $n->y, 'w' => $n->w, 'h' => $n->h];
            }
        } else {
            self::layout($root, 0, 0, $width, $boxes);
        }

        $widgets = [];
        self::collect($root, null, $widgets, $boxes);

        return ['title' => $title, 'width' => $width, 'height' => $height, 'widgets' => $widgets];
    }

    /** Find a widget by its declared id (null when absent). */
    public static function findById(array $snap, string $id): ?array
    {
        foreach ($snap['widgets'] as $w) {
            if (($w['id'] ?? null) === $id) {
                return $w;
            }
        }
        return null;
    }

    /** Find a widget by its display name (label/button text or window title). */
    public static function findByText(array $snap, string $text): ?array
    {
        foreach ($snap['widgets'] as $w) {
            if (($w['name'] ?? null) === $text) {
                return $w;
            }
        }
        return null;
    }

    /** All widgets of a given role (window|column|row|label|button). */
    public static function findByRole(array $snap, string $role): array
    {
        return array_values(array_filter(
            $snap['widgets'],
            static fn(array $w): bool => $w['role'] === $role,
        ));
    }

    /** Assign layout bounds; column/window stack vertically, row horizontally. */
    private static function layout(Element $el, int $x, int $y, int $w, array &$boxes): int
    {
        if ($el->type === 'row') {
            $n = count($el->children);
            $cw = $n > 0 ? intdiv($w, $n) : $w;
            $maxH = 0;
            $cx = $x;
            foreach ($el->children as $c) {
                $maxH = max($maxH, self::layout($c, $cx, $y, $cw, $boxes));
                $cx += $cw;
            }
            $boxes[spl_object_id($el)] = ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $maxH];
            return $maxH;
        }

        if ($el->type === 'column' || $el->type === 'window') {
            $cy = $y;
            $h = 0;
            foreach ($el->children as $c) {
                $h += self::layout($c, $x, $cy, $w, $boxes);
                $cy += self::ROW;
            }
            $boxes[spl_object_id($el)] = ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h];
            return $h;
        }

        if (in_array($el->type, ['stack', 'panel', 'list', 'toolbar', 'statusbar', 'titlebar', 'sidebar', 'split'], true)) {
            $cy = $y;
            $h = 0;
            foreach ($el->children as $c) {
                $h += self::layout($c, $x, $cy, $w, $boxes);
                $cy += self::ROW;
            }
            $boxes[spl_object_id($el)] = ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h];
            return $h;
        }

        $boxes[spl_object_id($el)] = ['x' => $x, 'y' => $y, 'w' => $w, 'h' => self::ROW];
        return self::ROW;
    }

    /**
     * @param list<array<string,mixed>> $widgets
     * @param array<int,array{x:int,y:int,w:int,h:int}> $boxes
     */
    private static function collect(Element $el, ?string $parentId, array &$widgets, array $boxes): void
    {
        $idKey = $el->prop('id');
        $key = is_string($idKey) ? $idKey : spl_object_id($el);
        $b = $boxes[$key] ?? ['x' => 0, 'y' => 0, 'w' => 0, 'h' => 0];
        $id = $el->prop('id');
        $id = is_string($id) ? $id : null;
        $role = $el->type;
        $name = match ($role) {
            'window' => (string) $el->prop('title', ''),
            'label', 'button', 'toggle', 'iconbutton' => (string) $el->prop('text', ''),
            'segmented', 'search' => (string) ($el->prop('text') ?? $el->prop('placeholder') ?? $el->prop('value', '') ?? ''),
            'list_item' => (string) $el->prop('title', ''),
            'webview' => (string) $el->prop('url', ''),
            'gpusurface' => 'gpu:' . $el->prop('width', 0) . 'x' . $el->prop('height', 0),
            default => '',
        };

        $entry = [
            'id' => $id,
            'role' => $role,
            'name' => $name,
            'enabled' => true,
            'parent_id' => $parentId,
            'x' => $b['x'], 'y' => $b['y'], 'w' => $b['w'], 'h' => $b['h'],
        ];

        $handler = match ($role) {
            'button' => $el->prop('onClick'),
            'input', 'textarea', 'search' => $el->prop('onInput'),
            'checkbox', 'radio', 'slider', 'select', 'toggle', 'segmented', 'split' => $el->prop('onChange'),
            'iconbutton' => $el->prop('onClick'),
            'list', 'list_item' => $el->prop('onSelect'),
            default => null,
        };
        $entry['handler'] = $handler;

        $state = [];
        if ($role === 'input' || $role === 'textarea') {
            $state['value'] = $el->prop('text', '');
        } elseif ($role === 'search') {
            $state['value'] = (string) $el->prop('text', '');
            $state['placeholder'] = (string) $el->prop('placeholder', '');
        } elseif ($role === 'checkbox' || $role === 'radio') {
            $state['checked'] = (bool) $el->prop('checked', false);
        } elseif ($role === 'slider') {
            $state['min'] = (int) $el->prop('min', 0);
            $state['max'] = (int) $el->prop('max', 100);
            $state['value'] = (int) $el->prop('value', 0);
        } elseif ($role === 'progress') {
            $state['value'] = (float) $el->prop('value', 0.0);
        } elseif ($role === 'select') {
            $state['options'] = $el->prop('options', []);
            $state['selected'] = (int) $el->prop('value', 0);
        } elseif ($role === 'segmented') {
            $state['options'] = $el->prop('options', []);
            $state['selected'] = (int) $el->prop('value', 0);
        } elseif ($role === 'toggle') {
            $state['on'] = (bool) $el->prop('on', false);
        } elseif ($role === 'list') {
            $state['items'] = $el->prop('items', []);
            $state['selected'] = (int) $el->prop('value', -1);
        } elseif ($role === 'list_item') {
            $state['title'] = (string) $el->prop('title', '');
            $state['subtitle'] = (string) ($el->prop('subtitle') ?? '');
            $state['icon'] = (string) ($el->prop('icon') ?? '');
        } elseif ($role === 'split') {
            $state['orientation'] = (string) $el->prop('orientation', 'horizontal');
            $state['position'] = (float) $el->prop('position', 0.5);
        } elseif ($role === 'webview') {
            $state['url'] = (string) $el->prop('url', '');
        } elseif ($role === 'gpusurface') {
            $state['width'] = (int) $el->prop('width', 0);
            $state['height'] = (int) $el->prop('height', 0);
        }
        $entry['state'] = $state;

        $widgets[] = $entry;

        $childParent = $id ?? $parentId;
        foreach ($el->children as $c) {
            self::collect($c, $childParent, $widgets, $boxes);
        }
    }
}
