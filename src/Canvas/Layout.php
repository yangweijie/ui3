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
    /** Per-id scroll offset overrides injected by the backend (programmatic scroll). */
    private static array $overrides = [];

    public static function compute(Element $root, array $overrides = []): array
    {
        self::$overrides = $overrides;
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

        $contentH = $h - $topH - $botH - self::PAD * 2;
        self::placeColumn($content, self::PAD + $leftW, self::PAD + $topH, $w - $leftW - self::PAD * 2, $nodes, max(0, $contentH));

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

    /**
     * Lay out a vertical stack. When $availH is known and some children carry a
     * `grow` weight, the leftover vertical space is distributed proportionally
     * among them (flex-grow). Children without grow keep their natural height.
     *
     * @param list<Element> $els
     * @param list<Node>    $nodes
     */
    private static function placeColumn(array $els, int $x, int $y, int $w, array &$nodes, int $availH = 0): int
    {
        if ($els === []) {
            return 0;
        }
        $nats = [];
        $total = 0;
        foreach ($els as $i => $el) {
            $nh = self::naturalHeight($el, $w);
            $nats[$i] = $nh;
            $total += $nh + self::PAD;
        }
        $total -= self::PAD;

        if ($availH > 0 && $total < $availH) {
            $grows = [];
            $gSum = 0;
            foreach ($els as $i => $el) {
                // A child participates if it grows itself OR contains a grow
                // descendant: in the latter case the container must expand so the
                // leftover can be forwarded to its growing child.
                $g = (float) $el->prop('grow', 0);
                if ($g > 0 || self::hasGrow($el)) {
                    $weight = $g > 0 ? $g : 1.0;
                    $grows[$i] = $weight;
                    $gSum += $weight;
                }
            }
            if ($gSum > 0) {
                $leftover = $availH - $total;
                foreach ($grows as $i => $weight) {
                    $nats[$i] += (int) ($leftover * $weight / $gSum);
                }
            }
        }

        $cy = $y;
        foreach ($els as $i => $el) {
            self::place($el, $x, $cy, $w, $nodes, $nats[$i]);
            $cy += $nats[$i] + self::PAD;
        }
        return $cy - $y - self::PAD;
    }

    /**
     * @param list<Node> $nodes
     */
    private static function placeRow(array $els, int $x, int $y, int $w, int $h, array &$nodes): int
    {
        if ($els === []) {
            return $h;
        }
        $nats = [];
        $total = 0;
        foreach ($els as $i => $el) {
            $nw = self::measure($el, $w)[0];
            $nats[$i] = $nw;
            $total += $nw + self::PAD;
        }
        $total -= self::PAD;

        $cx = $x + max(0, (int) (($w - $total) / 2));
        $grows = [];
        $gSum = 0;
        foreach ($els as $i => $el) {
            $g = (float) $el->prop('grow', 0);
            if ($g > 0) {
                $grows[$i] = $g;
                $gSum += $g;
            }
        }
        if ($gSum > 0 && $total < $w) {
            $leftover = $w - $total;
            foreach ($grows as $i => $g) {
                $nats[$i] += (int) ($leftover * $g / $gSum);
            }
            $cx = $x; // grow fills the row width -> left-align
        }

        foreach ($els as $i => $el) {
            $ew = $nats[$i];
            $eh = self::measure($el, $w)[1];
            self::place($el, $cx, $y + (int) (($h - $eh) / 2), $ew, $nodes);
            $cx += $ew + self::PAD;
        }
        return $h;
    }

    /**
     * @param list<Node> $nodes
     */
    private static function place(Element $el, int $x, int $y, int $w, array &$nodes, int $allottedH = 0): int
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
                    : self::placeColumn($el->children, $x, $y, $w, $nodes, $allottedH);

            case 'column':
                return self::placeColumn($el->children, $x, $y, $w, $nodes, $allottedH);

            case 'panel':
                $ph = $allottedH > 0 ? $allottedH : (self::ROW + 6 + (count($el->children) * (self::ROW + self::PAD)));
                $nodes[] = new Node($el, 'panel', $x, $y, $w, $ph);
                self::placeColumn($el->children, $x, $y + self::ROW + 6, $w, $nodes, max(0, $ph - self::ROW - 6));
                return $ph;

            case 'spacer':
                $sh = $allottedH > 0 ? $allottedH : (int) $el->prop('size', self::ROW);
                $nodes[] = new Node($el, 'spacer', $x, $y, $w, $sh);
                return $sh;

            case 'grid':
                return self::placeGrid($el, $x, $y, $w, $nodes);

            case 'absolute':
                return self::placeAbsolute($el, $x, $y, $w, $nodes);

            case 'positioned':
                return self::placePositioned($el, $x, $y, $w, $nodes, $allottedH);

            case 'tabs':
                return self::placeTabs($el, $x, $y, $w, $nodes);
            case 'card':
                $h = $allottedH > 0 ? $allottedH : self::measure($el, $w)[1];
                $nodes[] = new Node($el, 'card', $x, $y, $w, $h);
                self::placeColumn($el->children, $x, $y + 28, $w, $nodes);
                return $h;
            case 'accordion':
                return self::placeAccordion($el, $x, $y, $w, $nodes);
            case 'dialog':
            case 'sheet':
            case 'drawer':
                $h = $allottedH > 0 ? $allottedH : self::measure($el, $w)[1];
                $nodes[] = new Node($el, $el->type, $x, $y, $w, $h);
                self::placeColumn($el->children, $x + 8, $y + 28, $w - 16, $nodes);
                return $h;
            case 'scroll':
                $h = $allottedH > 0 ? $allottedH : self::measure($el, $w)[1];
                $nodes[] = new Node($el, 'scroll', $x, $y, $w, $h);
                $id = $el->prop('id');
                $off = (int)($id !== null && isset(self::$overrides[$id]) ? self::$overrides[$id] : $el->prop('offset', 0));
                self::placeColumn($el->children, $x, $y - $off, $w, $nodes);
                return $h;
            case 'menu':
                return self::placeMenu($el, $x, $y, $w, $nodes);
            case 'tree':
                return self::placeTree($el, $x, $y, $w, $nodes);
            case 'button_group':
                $h = $allottedH > 0 ? $allottedH : self::measure($el, $w)[1];
                $nodes[] = new Node($el, 'button_group', $x, $y, $w, $h);
                self::placeRow($el->children, $x, $y, $w, $h, $nodes);
                return $h;

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
                return self::placeList($el, $x, $y, $w, $nodes, $allottedH);

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
    private static function placeList(Element $el, int $x, int $y, int $w, array &$nodes, int $allottedH = 0): int
    {
        if (self::listHasItems($el)) {
            $items = $el->prop('items', []);
            $total = count($items) * self::LISTITEM;
            if ((bool) $el->prop('virtual', false)) {
                // windowed rendering: only materialize the visible `viewport` rows
                $vh = (int) $el->prop('viewport', 10);
                $h = $vh * self::LISTITEM;
                $nodes[] = new Node($el, 'list', $x, $y, $w, $h);
                $id = $el->prop('id');
                $scroll = $id !== null && isset(self::$overrides[$id]) ? (int) self::$overrides[$id] : (int) $el->prop('scroll', 0);
                $start = max(0, min($scroll, count($items) - 1));
                $end = min(count($items), $start + $vh);
                for ($i = $start; $i < $end; $i++) {
                    $iy = $y + ($i - $start) * self::LISTITEM;
                    $nodes[] = new Node(new Element('list_item', [
                        'id' => null,
                        'title' => (string) $items[$i],
                        '_onSelect' => $el->prop('onSelect'),
                        '_index' => $i,
                    ]), 'list_item', $x, $iy, $w, self::LISTITEM);
                }
                return $h;
            }
            $h = $allottedH > 0 ? $allottedH : $total;
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
                'id' => $c->prop('key') ?? $c->prop('id'),
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

    /**
     * Whether an element (or any descendant) carries a `grow` weight. Used so a
     * container expands and forwards leftover space to its growing child.
     */
    private static function hasGrow(Element $el): bool
    {
        if ((float) $el->prop('grow', 0) > 0) {
            return true;
        }
        foreach ($el->children as $c) {
            if (self::hasGrow($c)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Natural (content) height of an element, used by flex-grow distribution.
     * Containers sum their children; leaves defer to measure().
     */
    private static function naturalHeight(Element $el, int $w): int
    {
        switch ($el->type) {
            case 'column':
            case 'stack':
                if ($el->type === 'stack' && $el->prop('axis') === 'horizontal') {
                    $mh = 0;
                    foreach ($el->children as $c) {
                        $mh = max($mh, self::naturalHeight($c, $w));
                    }
                    return max(self::ROW, $mh);
                }
                $hh = 0;
                $n = count($el->children);
                foreach ($el->children as $c) {
                    $hh += self::naturalHeight($c, $w) + self::PAD;
                }
                return max(0, $hh - ($n > 0 ? self::PAD : 0));
            case 'panel':
                $hh = self::ROW + 6;
                foreach ($el->children as $c) {
                    $hh += self::naturalHeight($c, $w) + self::PAD;
                }
                return $hh;
            case 'list':
                if (self::listHasItems($el)) {
                    if ((bool) $el->prop('virtual', false)) {
                        return (int) $el->prop('viewport', 10) * self::LISTITEM;
                    }
                    return max(1, count($el->prop('items', []))) * self::LISTITEM;
                }
                $hh = 0;
                foreach ($el->children as $c) {
                    $hh += ($c->prop('subtitle') ? self::LISTITEM_SUB : self::LISTITEM);
                }
                return $hh;
            case 'row':
                $mh = 0;
                foreach ($el->children as $c) {
                    $mh = max($mh, self::naturalHeight($c, $w));
                }
                return max(self::ROW, $mh);
            case 'spacer':
                return (int) $el->prop('size', self::ROW);
            default:
                return self::measure($el, $w)[1];
        }
    }

    private static function placeGrid(Element $el, int $x, int $y, int $w, array &$nodes): int
    {
        $cols = max(1, (int) $el->prop('columns', 2));
        $cellW = intdiv($w, $cols);
        $rowH = (int) $el->prop('rowHeight', self::ROW);
        $n = count($el->children);
        $rows = (int) ceil($n / $cols);
        for ($i = 0; $i < $n; $i++) {
            $r = intdiv($i, $cols);
            $c = $i % $cols;
            self::place($el->children[$i], $x + $c * $cellW, $y + $r * $rowH, $cellW - self::PAD, $nodes);
        }
        return $rows * $rowH;
    }

    private static function placeAbsolute(Element $el, int $x, int $y, int $w, array &$nodes): int
    {
        foreach ($el->children as $c) {
            self::place($c, $x, $y, $w, $nodes);
        }
        return self::ROW;
    }

    private static function placePositioned(Element $el, int $x, int $y, int $w, array &$nodes, int $allottedH = 0): int
    {
        $l = (int) $el->prop('left', 0);
        $t = (int) $el->prop('top', 0);
        $ew = (int) $el->prop('width', $w);
        $eh = (int) $el->prop('height', $allottedH > 0 ? $allottedH : self::ROW);
        $child = $el->children[0] ?? null;
        if ($child) {
            $containerTypes = ['column', 'row', 'stack', 'panel', 'list', 'grid', 'absolute', 'split', 'toolbar', 'statusbar', 'titlebar', 'sidebar'];
            if (in_array($child->type, $containerTypes, true) || $child->children !== []) {
                self::place($child, $x + $l, $y + $t, $ew, $nodes, $eh);
            } else {
                // leaf control: honor the explicit box instead of re-measuring
                $nodes[] = new Node($child, $child->type, $x + $l, $y + $t, $ew, $eh);
            }
        }
        return $eh;
    }

    private static function listHasItems(Element $el): bool
    {
        $items = $el->prop('items');
        return is_array($items) && $items !== [];
    }

    private static function placeTabs(Element $el, int $x, int $y, int $w, array &$nodes): int
    {
        $tabs = $el->prop('tabs', []);
        $sel = (int) $el->prop('selected', 0);
        $stripH = 28;
        $h = $stripH + 160;
        $nodes[] = new Node($el, 'tabs', $x, $y, $w, $h);
        $n = count($tabs);
        $tw = $n > 0 ? intdiv($w, $n) : $w;
        foreach ($tabs as $i => $tp) {
            if ($tp instanceof Element) {
                $nodes[] = new Node($tp, 'tab', $x + $i * $tw, $y, $tw, $stripH);
            }
        }
        $page = $tabs[$sel] ?? null;
        if ($page instanceof Element) {
            self::placeColumn($page->children, $x, $y + $stripH, $w, $nodes);
        }
        return $h;
    }

    private static function placeAccordion(Element $el, int $x, int $y, int $w, array &$nodes): int
    {
        $sections = $el->prop('sections', []);
        $cy = $y;
        $hh = 0;
        foreach ($sections as $s) {
            $title = (string) ($s['title'] ?? '');
            $children = $s['children'] ?? [];
            $nodes[] = new Node(new Element('list_item', ['title' => $title, 'id' => null]), 'acc_header', $x, $cy, $w, self::ROW);
            $cy += self::ROW;
            $hh += self::ROW;
            if (!empty($s['expanded'])) {
                foreach ($children as $c) {
                    $rh = self::place($c, $x + 12, $cy, $w - 12, $nodes);
                    $cy += $rh + self::PAD;
                    $hh += $rh + self::PAD;
                }
            }
        }
        array_unshift($nodes, new Node($el, 'accordion', $x, $y, $w, max(self::ROW, $hh)));
        return max(self::ROW, $hh);
    }

    private static function placeMenu(Element $el, int $x, int $y, int $w, array &$nodes): int
    {
        $items = $el->prop('items', []);
        $nodes[] = new Node($el, 'menu', $x, $y, $w, self::ROW);
        $cy = $y;
        foreach ($items as $it) {
            if ($it instanceof Element) {
                self::place($it, $x, $cy, $w, $nodes);
            }
            $cy += 22;
        }
        return $cy - $y;
    }

    private static function placeTree(Element $el, int $x, int $y, int $w, array &$nodes): int
    {
        $nodes[] = new Node($el, 'tree', $x, $y, $w, self::ROW);
        $total = 0;
        foreach ($el->children as $node) {
            $total += self::placeTreeNode($node, $x, $y + $total, $w, $nodes, 0);
        }
        return max(self::ROW, $total);
    }

    private static function placeTreeNode(Element $el, int $x, int $y, int $w, array &$nodes, int $depth): int
    {
        $indent = $depth * 16;
        $nodes[] = new Node($el, 'tree_node', $x + $indent, $y, $w - $indent, self::ROW);
        $total = self::ROW;
        if ((bool) $el->prop('expanded', false)) {
            foreach ($el->children as $c) {
                $total += self::placeTreeNode($c, $x, $y + $total, $w, $nodes, $depth + 1);
            }
        }
        return $total;
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
                    if ((bool) $el->prop('virtual', false)) {
                        return [$w, (int) $el->prop('viewport', 10) * self::LISTITEM];
                    }
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
            case 'grid':
                $cols = max(1, (int) $el->prop('columns', 2));
                $rowH = (int) $el->prop('rowHeight', self::ROW);
                $rows = (int) ceil(count($el->children) / $cols);
                return [$availW, $rows * $rowH];
            case 'absolute':
                return [$w, self::ROW];
            case 'positioned':
                return [(int) $el->prop('width', $w), (int) $el->prop('height', self::ROW)];

            // ---- Phase 3 widgets ----
            case 'tabs':
                return [$w, 28 + 160];
            case 'tab':
                return [$w, 28];
            case 'card':
                return [$w, 28 + count($el->children) * (self::ROW + self::PAD)];
            case 'alert':
                return [$w, 36];
            case 'accordion':
                $ah = 0;
                foreach ($el->prop('sections', []) as $s) {
                    $ah += self::ROW;
                    if (!empty($s['expanded'])) {
                        $ah += count($s['children'] ?? []) * (self::ROW + self::PAD);
                    }
                }
                return [$w, max(self::ROW, $ah)];
            case 'dialog':
            case 'sheet':
            case 'drawer':
                return [$w, 220];
            case 'scroll':
                return [$w, 160];
            case 'table':
                return [$w, 24 + count($el->prop('rows', [])) * 20];
            case 'combobox':
            case 'dropdown':
                return [$w, 28];
            case 'menu':
                return [$w, count($el->prop('items', [])) * 22];
            case 'menu_item':
                return [$w, 22];
            case 'tree':
                return [$w, max(self::ROW, count($el->children) * self::ROW)];
            case 'tree_node':
                return [$w, self::ROW];
            case 'chart':
                return [$w, 120];
            case 'tooltip':
                return [(int) self::textWidth((string) $el->prop('text', ''), 11) + 12, 24];
            case 'badge':
                return [(int) self::textWidth((string) $el->prop('text', ''), 11) + 12, 22];
            case 'avatar':
                return [40, 40];
            case 'skeleton':
                return [$w, (int) $el->prop('lines', 3) * 14 + 8];
            case 'spinner':
                return [24, 24];
            case 'switch':
                return [44, 22];
            case 'richtext':
                return [$w, max(16, count($el->prop('spans', [])) * 16 + 8)];
            case 'breadcrumb':
                return [$w, 24];
            case 'pagination':
                return [$w, 28];
            case 'button_group':
                return [$w, 30];
            default:
                return [max(40, $w), self::ROW];
        }
    }
}
