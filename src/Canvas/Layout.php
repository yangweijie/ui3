<?php
declare(strict_types=1);

namespace Yangweijie\Ui3\Canvas;

use Yangweijie\Ui3\Element;
use Yangweijie\Ui3\FFI\Cairo;

/**
 * Pure layout engine: turns an Element tree into absolute boxes.
 *
 * The window may contain up to four "region" components among its children:
 * titlebar_accessory (top), toolbar (top), sidebar (left) and statusbar
 * (bottom). Those are carved out of the window; every other child is laid out
 * as a content column in the remaining area. The canvas host has no OS chrome,
 * so these regions are emulated as styled strips — sufficient for the UI tree
 * and automation, while a real native backend would map them to OS chrome.
 *
 * @return list<Node>
 */
final class Layout
{
    public const PAD = 12;
    public const ROW = 32;
    public const LISTITEM = 20;
    public const LISTITEM_SUB = 36;
    public const DIVIDER = 6;
    public const TITLEBAR_H = 28;
    public const TOOLBAR_H = 36;
    public const SIDEBAR_W = 180;
    public const STATUSBAR_H = 28;

    public static function textWidth(string $s, float $size = 13.0): float
    {
        return Cairo::measureText($s, $size)['w'];
    }

    /** @return list<Node> */
    public static function compute(Element $root): array
    {
        $nodes = [];
        $w = (int) $root->prop('width', 320);
        $h = (int) $root->prop('height', 240);
        $nodes[] = new Node($root, 'window', 0, 0, $w, $h);

        $titlebar = null; $toolbar = null; $sidebar = null; $statusbar = null;
        $content = [];
        foreach ($root->children as $c) {
            switch ($c->type) {
                case 'titlebar': $titlebar = $c; break;
                case 'toolbar': $toolbar = $c; break;
                case 'sidebar': $sidebar = $c; break;
                case 'statusbar': $statusbar = $c; break;
                default: $content[] = $c;
            }
        }

        $topH = ($titlebar ? self::TITLEBAR_H : 0) + ($toolbar ? self::TOOLBAR_H : 0);
        $leftW = $sidebar ? self::SIDEBAR_W : 0;
        $botH = $statusbar ? self::STATUSBAR_H : 0;

        if ($titlebar) {
            $nodes[] = new Node($titlebar, 'titlebar', $leftW, 0, $w - $leftW, self::TITLEBAR_H);
            self::placeRow($titlebar->children, $leftW, 0, $w - $leftW, self::TITLEBAR_H, $nodes);
        }
        if ($toolbar) {
            $ty = $titlebar ? self::TITLEBAR_H : 0;
            $nodes[] = new Node($toolbar, 'toolbar', $leftW, $ty, $w - $leftW, self::TOOLBAR_H);
            self::placeRow($toolbar->children, $leftW, $ty, $w - $leftW, self::TOOLBAR_H, $nodes);
        }
        if ($sidebar) {
            $nodes[] = new Node($sidebar, 'sidebar', 0, $topH, $leftW, $h - $topH - $botH);
            self::placeColumn($sidebar->children, 0, $topH, $leftW, $nodes);
        }
        if ($statusbar) {
            $nodes[] = new Node($statusbar, 'statusbar', $leftW, $h - $botH, $w - $leftW, self::STATUSBAR_H);
            self::placeRow($statusbar->children, $leftW, $h - $botH, $w - $leftW, self::STATUSBAR_H, $nodes);
        }

        self::placeColumn($content, self::PAD + $leftW, self::PAD + $topH, $w - $leftW - self::PAD * 2, $nodes);

        return $nodes;
    }

    /**
     * @param list<Element> $els
     * @param list<Node>    $nodes
     */
    /**
     * Topmost node at (x, y). When several boxes overlap (e.g. a list row over
     * its list background, or a control over its container), the smallest box
     * wins — that is the most specific (innermost) control.
     */
    public static function hitTest(array $nodes, float $x, float $y): ?Node
    {
        $best = null;
        $bestArea = PHP_INT_MAX;
        foreach ($nodes as $n) {
            if ($x >= $n->x && $x <= $n->x + $n->w && $y >= $n->y && $y <= $n->y + $n->h) {
                $area = $n->w * $n->h;
                if ($area < $bestArea) {
                    $best = $n;
                    $bestArea = $area;
                }
            }
        }
        return $best;
    }

    private static function placeColumn(array $els, int $x, int $y, int $w, array &$nodes): int
    {
        $cy = $y;
        foreach ($els as $el) {
            $hh = self::place($el, $x, $cy, $w, $nodes);
            $cy += $hh + self::PAD;
        }
        return $cy - $y - (count($els) > 0 ? self::PAD : 0);
    }

    /**
     * @param list<Node> $nodes
     */
    private static function placeRow(array $els, int $x, int $y, int $w, int $h, array &$nodes): int
    {
        if ($els === []) {
            return $h;
        }
        $tw = 0;
        foreach ($els as $el) {
            [$ew] = self::measure($el, $w);
            $tw += $ew + self::PAD;
        }
        $tw -= self::PAD;
        $cx = $x + max(0, (int) (($w - $tw) / 2));
        foreach ($els as $el) {
            [$ew, $eh] = self::measure($el, $w);
            self::place($el, $cx, $y + (int) (($h - $eh) / 2), $ew, $nodes);
            $cx += $ew + self::PAD;
        }
        return $h;
    }

    /**
     * @param list<Node> $nodes
     */
    private static function place(Element $el, int $x, int $y, int $w, array &$nodes): int
    {
        switch ($el->type) {
            case 'row':
                $n = count($el->children);
                $cw = $n > 0 ? intdiv($w, $n) : $w;
                $cy = $y;
                foreach ($el->children as $c) {
                    self::place($c, $x, $cy, $cw, $nodes);
                    $x += $cw;
                }
                return self::ROW;

            case 'stack':
                return $el->prop('axis') === 'horizontal'
                    ? self::placeRow($el->children, $x, $y, $w, self::ROW, $nodes)
                    : self::placeColumn($el->children, $x, $y, $w, $nodes);

            case 'column':
                return self::placeColumn($el->children, $x, $y, $w, $nodes);

            case 'panel':
                $nodes[] = new Node($el, 'panel', $x, $y, $w, self::ROW);
                self::placeColumn($el->children, $x, $y + self::ROW + 6, $w, $nodes);
                return self::ROW + 6 + (count($el->children) * (self::ROW + self::PAD));

            case 'toolbar':
            case 'statusbar':
            case 'titlebar':
                $hh = $el->type === 'titlebar' ? self::TITLEBAR_H : ($el->type === 'toolbar' ? self::TOOLBAR_H : self::STATUSBAR_H);
                $nodes[] = new Node($el, $el->type, $x, $y, $w, $hh);
                self::placeRow($el->children, $x, $y, $w, $hh, $nodes);
                return $hh;

            case 'sidebar':
                $nodes[] = new Node($el, 'sidebar', $x, $y, $w, self::ROW);
                $hh = self::placeColumn($el->children, $x, $y, $w, $nodes);
                return max(self::ROW, $hh);

            case 'split':
                return self::placeSplit($el, $x, $y, $w, $nodes);

            case 'list':
                return self::placeList($el, $x, $y, $w, $nodes);

            case 'toggle':
            case 'iconbutton':
            case 'segmented':
            case 'search':
            case 'list_item':
            case 'webview':
            case 'gpusurface':
            case 'label':
            case 'button':
            case 'heading':
            case 'input':
            case 'textarea':
            case 'checkbox':
            case 'radio':
            case 'slider':
            case 'progress':
            case 'select':
            case 'image':
            case 'spacer':
            case 'divider':
            default:
                [$mw, $mh] = self::measure($el, $w);
                $nodes[] = new Node($el, $el->type, $x, $y, $mw, $mh);
                return $mh;
        }
    }

    /**
     * @param list<Node> $nodes
     */
    private static function placeSplit(Element $el, int $x, int $y, int $w, array &$nodes): int
    {
        $nodes[] = new Node($el, 'split', $x, $y, $w, self::ROW);
        $vertical = $el->prop('orientation') === 'vertical';
        $pos = max(0.05, min(0.95, (float) $el->prop('position', 0.5)));
        $panes = $el->children;
        $a = $panes[0] ?? null;
        $b = $panes[1] ?? null;

        if ($vertical) {
            $divY = (int) ($y + $pos * self::ROW);
            if ($a) {
                self::place($a, $x, $y, $w, $nodes);
            }
            if ($b) {
                self::place($b, $x, $divY + self::DIVIDER, $w, $nodes);
            }
            return self::ROW;
        }

        $divX = (int) ($x + $pos * $w);
        if ($a) {
            self::place($a, $x, $y, $divX - (int) (self::DIVIDER / 2) - $x, $nodes);
        }
        if ($b) {
            self::place($b, $divX + (int) (self::DIVIDER / 2), $y, $x + $w - ($divX + (int) (self::DIVIDER / 2)), $nodes);
        }
        return self::ROW;
    }

    /**
     * @param list<Node> $nodes
     */
    private static function placeList(Element $el, int $x, int $y, int $w, array &$nodes): int
    {
        if (self::listHasItems($el)) {
            $items = $el->prop('items', []);
            $h = count($items) * self::LISTITEM;
            $nodes[] = new Node($el, 'list', $x, $y, $w, $h);
            self::placeColumnOf($el, $items, $x, $y, $w, $nodes);
            return $h;
        }
        $cy = $y;
        $h = 0;
        $idx = 0;
        foreach ($el->children as $c) {
            $rh = ($c->type === 'list_item' && $c->prop('subtitle')) ? self::LISTITEM_SUB : self::LISTITEM;
            $nodes[] = new Node(new Element('list_item', [
                'id' => $c->prop('id'),
                'icon' => $c->prop('icon'),
                'title' => $c->prop('title'),
                'subtitle' => $c->prop('subtitle'),
                '_onSelect' => $el->prop('onSelect'),
                '_index' => $idx,
            ]), 'list_item', $x, $cy, $w, $rh);
            $cy += $rh;
            $h += $rh;
            $idx++;
        }
        $nodes[] = new Node($el, 'list', $x, $y, $w, $h);
        return $h;
    }

    /**
     * @param list<string> $items
     * @param list<Node>    $nodes
     */
    private static function placeColumnOf(Element $el, array $items, int $x, int $y, int $w, array &$nodes): void
    {
        $cy = $y;
        foreach ($items as $i => $it) {
            $nodes[] = new Node(new Element('list_item', [
                'id' => null,
                'title' => (string) $it,
                '_onSelect' => $el->prop('onSelect'),
                '_index' => $i,
            ]), 'list_item', $x, $cy, $w, self::LISTITEM);
            $cy += self::LISTITEM;
        }
    }

    private static function listHasItems(Element $el): bool
    {
        $items = $el->prop('items');
        return is_array($items) && $items !== [];
    }

    /**
     * @return array{0:int,1:int}
     */
    private static function measure(Element $el, int $availW): array
    {
        $w = $availW;
        switch ($el->type) {
            case 'heading':
                $tw = (int) self::textWidth((string) $el->prop('text', ''), 20);
                return [$tw + 8, 30];
            case 'label':
                $tw = (int) self::textWidth((string) $el->prop('text', ''), 13);
                return [$tw + 4, self::ROW];
            case 'button':
                $tw = (int) self::textWidth((string) $el->prop('text', ''), 13);
                return [$tw + 28, 30];
            case 'iconbutton':
                $label = (string) $el->prop('text', '');
                $tw = 22 + (int) self::textWidth($label === '' ? (string) $el->prop('icon', '') : (string) $el->prop('icon', '') . ' ' . $label, 13);
                return [$tw + 14, 30];
            case 'toggle':
                $tw = (int) self::textWidth((string) $el->prop('text', ''), 13);
                return [$tw + 52, 24];
            case 'input':
                $tw = max(80, min(220, (int) self::textWidth((string) $el->prop('text', '') ?: (string) $el->prop('placeholder', ''), 13) + 20));
                return [$tw, 28];
            case 'search':
                $tw = max(80, min(240, (int) self::textWidth((string) $el->prop('text', '') ?: (string) $el->prop('placeholder', ''), 13) + 36));
                return [$tw, 28];
            case 'textarea':
                return [max(120, min(320, $w)), 80];
            case 'checkbox':
            case 'radio':
                $tw = (int) self::textWidth((string) $el->prop('text', ''), 13);
                return [$tw + 26, self::ROW];
            case 'slider':
                return [max(120, min(260, $w)), 24];
            case 'progress':
                return [max(80, min(260, $w)), 14];
            case 'select':
                $items = $el->prop('options', []);
                $tw = 30;
                foreach ($items as $it) {
                    $tw = max($tw, (int) self::textWidth((string) $it, 13) + 30);
                }
                return [min(220, $tw), 28];
            case 'segmented':
                $tw = 0;
                foreach ($el->prop('options', []) as $opt) {
                    $tw += (int) self::textWidth((string) $opt, 13) + 18;
                }
                return [max(40, $tw), 28];
            case 'list':
                if (self::listHasItems($el)) {
                    return [$w, count($el->prop('items', [])) * self::LISTITEM];
                }
                $hh = 0;
                foreach ($el->children as $c) {
                    $hh += ($c->type === 'list_item' && $c->prop('subtitle')) ? self::LISTITEM_SUB : self::LISTITEM;
                }
                return [$w, $hh];
            case 'list_item':
                return [$w, $el->prop('subtitle') ? self::LISTITEM_SUB : self::LISTITEM];
            case 'spacer':
                return [4, (int) $el->prop('size', self::ROW)];
            case 'divider':
                return [$w, 1];
            case 'image':
                return [(int) $el->prop('width', 48), (int) $el->prop('height', 48)];
            case 'webview':
                return [max(80, min(320, $w)), max(60, 160)];
            case 'gpusurface':
                return [(int) $el->prop('width', 200), (int) $el->prop('height', 120)];
            case 'titlebar':
                return [$w, self::TITLEBAR_H];
            case 'toolbar':
                return [$w, self::TOOLBAR_H];
            case 'statusbar':
                return [$w, self::STATUSBAR_H];
            case 'sidebar':
            case 'panel':
            case 'stack':
            case 'split':
                return [$w, self::ROW];
            default:
                return [max(40, $w), self::ROW];
        }
    }
}
