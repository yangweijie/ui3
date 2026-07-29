<?php
declare(strict_types=1);

namespace Yangweijie\Ui3\Backends;

use Kingbes\Phpc\Phpc;
use Yangweijie\Ui3\Animation;
use Yangweijie\Ui3\Backend;
use Yangweijie\Ui3\Canvas\Layout;
use Yangweijie\Ui3\Canvas\Node;
use Yangweijie\Ui3\Element;
use Yangweijie\Ui3\FFI\{Cairo, LibUi3};
use Yangweijie\Ui3\Theme;

/**
 * Canvas backend (libui-free). Owns a native window host via libui3 and paints
 * the whole Element tree with Cairo; the host hands us a cairo_t* on each frame
 * and forwards pointer/keyboard coordinates, which we hit-test against the
 * layout computed in PHP.
 *
 * In headless mode the host renders to an offscreen surface, so automation can
 * drive it via ui3_host_inject_pointer without a display.
 */
final class Canvas implements Backend
{
    private $ffi;
    private $host = null;
    private ?Element $root = null;
    private ?\Closure $dispatch = null;
    /** @var list<Node> */
    private array $layout = [];
    /** @var array<object> keep callbacks alive while the host lives */
    private array $keep = [];
    /** Id of the currently focused text field (drives the KEY event path). */
    private ?string $focusId = null;
    /** Per-field edit buffers (text + cursor) for text controls. */
    private array $fields = [];
    /** Per-widget highlight index for list/select keyboard browsing. */
    private array $highlights = [];
    /** Expanded state for dropdown popups (view state, not model). */
    private array $expanded = [];
    /** In-progress pointer drag (slider), keyed by widget id. */
    private ?array $drag = null;
    /** Count of frames actually painted (real-window draw path proof). */
    private int $framesDrawn = 0;
    /** Active design tokens (colors/fonts/radius), resolved from a Theme. */
    private array $theme = [];
    /** Animation clock in seconds. Real windows use wall-clock; tests freeze it. */
    private float $clock = 0.0;
    /** When true, `clock` comes from setTime() instead of microtime(). */
    private bool $manualClock = false;
    /** @var ?float absolute time used while $manualClock is true */
    private ?float $clockOverride = null;
    /** @var array<string,float> element id => animation start time (seconds) */
    private array $animStart = [];
    /** @var array<string,array{alpha:float,dx:float,dy:float,scale:float,done:bool}> */
    private array $animStates = [];
    /** Per-id scroll offset overrides (programmatic wheel/scroll). int by id. */
    private array $scrollOverrides = [];
    /** Rubber-band elastic overshoot in px, keyed by id; decays to 0 each frame. */
    private array $scrollElastic = [];
    /** Clock time until which scrollbars stay fully visible (native overlay behaviour). */
    private float $scrollbarVisibleUntil = 0.0;
    /** Open context menus keyed by element id => item list. */
    private array $contextMenus = [];
    /** @var array<string,array{phase:string,text:string}> IME composition preview state. */
    private array $composition = [];
    /** Scroll container id activated by pointer-down, for arrow-key scrolling. */
    private ?string $activeScrollId = null;

    /** In-memory clipboard mirror; synced to the native host clipboard when a real window exists. */
    private string $clipboard = '';

    private const POINTER_DOWN = 1;
    private const POINTER_UP = 2;
    private const POINTER_MOVE = 3;
    private const KEY = 4;
    private const KEY_ESC = "\x1b";
    private const MB_LEFT = 1;   // normalized pointer button: left
    private const MB_RIGHT = 2;  // normalized pointer button: right
    private const MENU_ROW_H = 24;
    private const MENU_PAD = 8;
    private const KEY_LEFT = "\x01";
    private const KEY_RIGHT = "\x02";
    private const KEY_UP = "\x03";
    private const KEY_DOWN = "\x04";
    private const KEY_HOME = "\x05";
    private const KEY_END = "\x06";
    private const KEY_DEL = "\x07";
    private const KEY_SHIFT_LEFT = "\x11";
    private const KEY_SHIFT_RIGHT = "\x12";
    private const KEY_SHIFT_UP = "\x13";
    private const KEY_SHIFT_DOWN = "\x14";
    /** Mouse-wheel event (host ABI UI3_EVENT_WHEEL). data = pixels scrolled. */
    private const WHEEL = 5;
    /** Widget types that join the Tab order and show a focus ring. */
    private const FOCUSABLE = ['input', 'textarea', 'button', 'iconbutton', 'checkbox', 'radio', 'select', 'list', 'toggle', 'segmented', 'search'];
    /** Widget types browsable with arrow keys + Enter. */
    private const NAVIGABLE = ['list', 'select', 'segmented'];
    private const SCROLLBAR_IDLE = 0.9;   // seconds visible after last scroll/hover
    private const SCROLLBAR_FADE = 0.5;   // seconds to fade out once idle
    private const SCROLL_ELASTIC_DAMP = 0.4; // fraction of overshoot shown visually

    public function __construct(private bool $headless = false)
    {
        $this->ffi = LibUi3::ffi();
        $this->theme = Theme::get(Theme::LIGHT);
    }

    /**
     * Switch the active design tokens. Accepts a Theme name (Theme::LIGHT /
     * Theme::DARK) or a raw token array. Drives all subsequent paints.
     */
    public function setTheme(string|array $theme): void
    {
        $this->theme = Theme::get($theme);
    }

    /** Current resolved tokens (for tests / automation introspection). */
    public function theme(): array
    {
        return $this->theme;
    }

    /** Resolve a color token to a [r,g,b] triple (0..1), falling back to black. */
    public function col(string $name): array
    {
        return $this->theme[$name] ?? [0.0, 0.0, 0.0];
    }

    /** Resolve a non-color token (radius/font/fontSize), with a default. */
    public function tkn(string $name, mixed $default = null): mixed
    {
        return $this->theme[$name] ?? $default;
    }

    // ---- animation: a clock-driven ticker + per-element interpolation ----

    /** Freeze the animation clock at t=0 so a test can advance it deterministically. */
    public function freezeClock(): void
    {
        $this->manualClock = true;
        $this->clockOverride = 0.0;
    }

    /** Set the absolute animation clock (seconds). Only meaningful after freezeClock(). */
    public function setTime(float $seconds): void
    {
        $this->clockOverride = $seconds;
    }

    /** Current clock value (seconds). */
    public function clock(): float
    {
        return $this->clock;
    }

    /**
     * Computed animation state for an element id, populated on the last paint:
     * ['alpha'=>float, 'dx'=>float, 'dy'=>float, 'scale'=>float, 'done'=>bool].
     */
    public function animState(string $id): ?array
    {
        return $this->animStates[$id] ?? null;
    }

    /** True while at least one element still has an in-flight animation. */
    public function isAnimating(): bool
    {
        foreach ($this->animStates as $s) {
            if (!$s['done']) {
                return true;
            }
        }
        return false;
    }

    /** Ask the native host to repaint (real windows only). */
    public function requestRedraw(): void
    {
        if ($this->host) {
            Phpc::call($this->ffi, 'ui3_host_request_redraw', [$this->host]);
        }
    }

    /** Wall-clock now, matching the value paint() assigns to $this->clock. */
    private function now(): float
    {
        return $this->manualClock ? (float) ($this->clockOverride ?? 0.0) : microtime(true);
    }

    // ---- input: keyboard focus, scroll, context menu, gestures ----

    /** Advance focus forward (Tab). */
    public function tabForward(): void
    {
        $this->moveFocus(1);
    }

    /** Move focus backward (Shift+Tab). */
    public function tabBackward(): void
    {
        $this->moveFocus(-1);
    }

    /** Programmatically scroll a list/scroll widget by $delta rows/pixels. */
    public function scrollBy(string $id, int $delta): void
    {
        if ($this->layout === [] && $this->root) {
            $this->layout = Layout::compute($this->root);
        }
        $node = $this->findNodeById($id);
        if ($node === null) {
            return;
        }
        $sid = (string) $node->el->prop('id');
        $cur = $this->scrollOffset($id);
        $maxOff = max(0, Layout::scrollContentHeight($sid) - (int) $node->h);
        $target = $cur + $delta;
        if ($target < 0) {
            $this->scrollOverrides[$id] = 0;
            $this->scrollElastic[$id] = $target; // negative overshoot (rubber-band)
        } elseif ($target > $maxOff) {
            $this->scrollOverrides[$id] = $maxOff;
            $this->scrollElastic[$id] = $target - $maxOff; // positive overshoot
        } else {
            $this->scrollOverrides[$id] = $target;
            // residual elastic (if any) keeps decaying toward 0
        }
        $this->scrollbarVisibleUntil = $this->now() + self::SCROLLBAR_IDLE;
        $msg = $node->el->prop('onScroll');
        if (is_string($msg) && $msg !== '') {
            ($this->dispatch)($msg, $this->scrollOverrides[$id]);
        }
        $this->requestRedraw();
    }

    /** Rendered offset = clamped target + damped rubber-band overshoot. */
    private function effectiveScrollOffset(string $id): int
    {
        $off = $this->scrollOffset($id);
        $e = $this->scrollElastic[$id] ?? 0.0;
        return (int) round($off + $e * self::SCROLL_ELASTIC_DAMP);
    }

    /** Current scroll offset for a list/scroll widget (pixels). */
    public function scrollOffset(string $id): int
    {
        if (isset($this->scrollOverrides[$id])) {
            return (int) $this->scrollOverrides[$id];
        }
        $node = $this->findNodeById($id);
        if ($node === null) {
            return 0;
        }
        $raw = (int) $node->el->prop('scroll', $node->el->prop('offset', 0));
        // A list's `scroll` prop is an item index; convert to pixels to match
        // the scroll-container model (px) used everywhere else.
        return $node->type === 'list' ? $raw * Layout::LISTITEM : $raw;
    }

    /**
     * Feed an IME composition event for a field (start/update/end). Mirrors the
     * headless Reference backend so the native path can also show a candidate
     * preview. The next paint draws it after the committed value.
     */
    public function composition(string $id, string $phase, string $text): void
    {
        if ($phase === 'end') {
            unset($this->composition[$id]);
        } else {
            $this->composition[$id] = ['phase' => $phase, 'text' => $text];
        }
        $this->requestRedraw();
    }

    /** Open the context menu attached to an element (right-click equivalent).
     *  $x/$y position the menu (clamped to the window); $items overrides the
     *  element's `contextMenu` prop (used for the built-in text-edit menu). */
    public function openContextMenu(string $id, float $x = 0.0, float $y = 0.0, ?array $items = null): void
    {
        $node = $this->findNodeById($id);
        if ($node === null) {
            return;
        }
        $items ??= $node->el->prop('contextMenu');
        if (!is_array($items) || $items === []) {
            return;
        }
        [$mw, $mh] = $this->contextMenuSize($items);
        [$cx, $cy] = $this->clampMenuPos($x, $y, $mw, $mh);
        $this->contextMenus[$id] = [
            'x' => $cx, 'y' => $cy, 'w' => $mw, 'h' => $mh, 'items' => $items,
            'hover' => -1, 'submenus' => [],
        ];
        if ($this->host) {
            Phpc::call($this->ffi, 'ui3_host_request_redraw', [$this->host]);
        }
    }

    /** Close every open context menu. */
    public function closeContextMenus(): void
    {
        $this->contextMenus = [];
    }

    public function isContextMenuOpen(string $id): bool
    {
        return isset($this->contextMenus[$id]);
    }

    /** Items of an open context menu (empty if none). */
    public function contextMenuItems(string $id): array
    {
        return $this->contextMenus[$id]['items'] ?? [];
    }

    /** Bounding rect [x, y, w, h] of an open context menu, or null. */
    public function contextMenuRect(string $id): ?array
    {
        $menu = $this->contextMenus[$id] ?? null;
        return $menu === null ? null : [$menu['x'], $menu['y'], $menu['w'], $menu['h']];
    }

    /** Bounding rect [x, y, w, h] of a single menu row, or null. */
    public function contextMenuItemRect(string $id, int $index): ?array
    {
        $menu = $this->contextMenus[$id] ?? null;
        if ($menu === null || $index < 0 || $index >= count($menu['items'])) {
            return null;
        }
        return [
            $menu['x'],
            $menu['y'] + self::MENU_PAD + $index * self::MENU_ROW_H,
            $menu['w'],
            self::MENU_ROW_H,
        ];
    }

    /** Hovered row index of an open context menu (-1 = none), or -1 if closed. */
    public function contextMenuHover(string $id): int
    {
        return $this->contextMenus[$id]['hover'] ?? -1;
    }

    /** Items of the deepest open submenu (empty if none). */
    public function contextSubmenuItems(string $id): array
    {
        $subs = $this->contextMenus[$id]['submenus'] ?? [];
        return $subs === [] ? [] : end($subs)['items'];
    }

    /** Bounding rect [x, y, w, h] of the deepest open submenu, or null. */
    public function contextSubmenuRect(string $id): ?array
    {
        $subs = $this->contextMenus[$id]['submenus'] ?? [];
        if ($subs === []) {
            return null;
        }
        $s = end($subs);
        return [$s['x'], $s['y'], $s['w'], $s['h']];
    }

    /** Bounding rect [x, y, w, h] of a single deepest-submenu row, or null. */
    public function contextSubmenuItemRect(string $id, int $i): ?array
    {
        $subs = $this->contextMenus[$id]['submenus'] ?? [];
        if ($subs === []) {
            return null;
        }
        return $this->menuItemRect(end($subs), $i);
    }

    /** Hovered row index of the deepest open submenu (-1 = none). */
    public function contextSubmenuHover(string $id): int
    {
        $subs = $this->contextMenus[$id]['submenus'] ?? [];
        return $subs === [] ? -1 : end($subs)['hover'];
    }

    /** Number of open submenu levels (0 = none, 1 = single, 2+ = nested). */
    public function contextSubmenuDepth(string $id): int
    {
        return count($this->contextMenus[$id]['submenus'] ?? []);
    }

    /** Items of the open submenu at a 1-based level (empty if absent). */
    public function contextSubmenuLevelItems(string $id, int $level): array
    {
        $subs = $this->contextMenus[$id]['submenus'] ?? [];
        return ($level >= 1 && isset($subs[$level - 1])) ? $subs[$level - 1]['items'] : [];
    }

    /** Bounding rect [x, y, w, h] of the open submenu at a 1-based level, or null. */
    public function contextSubmenuLevelRect(string $id, int $level): ?array
    {
        $subs = $this->contextMenus[$id]['submenus'] ?? [];
        if ($level < 1 || !isset($subs[$level - 1])) {
            return null;
        }
        $s = $subs[$level - 1];
        return [$s['x'], $s['y'], $s['w'], $s['h']];
    }

    /** Computed clipboard-preview text for a preview row (null if not a preview). */
    public function contextMenuPreviewText(string $id, int $i): ?string
    {
        $menu = $this->contextMenus[$id] ?? null;
        if ($menu === null || $i < 0 || $i >= count($menu['items'])) {
            return null;
        }
        return $this->menuPreviewText($menu['items'][$i]);
    }

    private function menuItemRect(array $menu, int $i): array
    {
        return [
            $menu['x'],
            $menu['y'] + self::MENU_PAD + $i * self::MENU_ROW_H,
            $menu['w'],
            self::MENU_ROW_H,
        ];
    }

    private function rowIndexAt(array $menu, float $x, float $y): int
    {
        $idx = (int)floor(($y - $menu['y'] - self::MENU_PAD) / self::MENU_ROW_H);
        return ($idx >= 0 && $idx < count($menu['items'])) ? $idx : -1;
    }

    private function pointInRect(float $x, float $y, float $mx, float $my, float $mw, float $mh): bool
    {
        return $x >= $mx && $x <= $mx + $mw && $y >= $my && $y <= $my + $mh;
    }

    private function menuPreviewText(array $it): ?string
    {
        if (!isset($it['preview']) || $it['preview'] !== 'clipboard') {
            return null;
        }
        $text = $this->clipboard();
        if ($text === '') {
            return '(empty)';
        }
        $max = 40;
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) . '…' : $text;
    }

    /** Build a submenu panel hanging off a parent row (no deeper children yet). */
    private function makeSubmenuPanel(string $id, int $parentDepth, int $parentIndex, array $parentPanel): array
    {
        $items = $parentPanel['items'][$parentIndex]['submenu'] ?? null;
        if (!is_array($items) || $items === []) {
            return [];
        }
        [$mw, $mh] = $this->contextMenuSize($items);
        $pr = $this->menuItemRect($parentPanel, $parentIndex);
        $ww = $this->root ? (int)$this->root->prop('width', 320) : 320;
        $wh = $this->root ? (int)$this->root->prop('height', 240) : 240;
        $sx = $pr[0] + $pr[2];
        if ($sx + $mw > $ww) {
            $sx = $pr[0] - $mw;
        }
        $sy = $pr[1];
        if ($sy + $mh > $wh) {
            $sy = max(0, $wh - $mh);
        }
        return [
            'parent' => $parentIndex,
            'items' => $items,
            'x' => (float)$sx, 'y' => (float)$sy, 'w' => $mw, 'h' => $mh,
            'hover' => -1,
        ];
    }

    /** Track which row (and which nested submenu) the pointer is over while a menu is open. */
    private function updateMenuHover(float $x, float $y): void
    {
        $changed = false;
        foreach ($this->contextMenus as $id => $menuData) {
            $menu = $menuData;
            $panels = array_merge([$menu], $menu['submenus']);
            // Deepest panel (topmost) containing the pointer wins.
            $foundAt = -1;
            for ($k = count($panels) - 1; $k >= 0; $k--) {
                $p = $panels[$k];
                if ($this->pointInRect($x, $y, $p['x'], $p['y'], $p['w'], $p['h'])) {
                    $foundAt = $k;
                    break;
                }
            }
            if ($foundAt === -1) {
                // Pointer outside every panel: clear root hover, close all submenus.
                if ($menu['hover'] !== -1) {
                    $menu['hover'] = -1;
                    $changed = true;
                }
                if ($menu['submenus'] !== []) {
                    $menu['submenus'] = [];
                    $changed = true;
                }
                $this->contextMenus[$id] = $menu;
                continue;
            }
            $panel = $panels[$foundAt];
            $idx = $this->rowIndexAt($panel, $x, $y);
            if ($panel['hover'] !== $idx) {
                $panel['hover'] = $idx;
                $changed = true;
            }
            $item = ($idx >= 0 && isset($panel['items'][$idx])) ? $panel['items'][$idx] : null;
            $hasSub = is_array($item)
                && isset($item['submenu'])
                && !isset($item['action'])
                && !isset($item['msg']);
            if ($hasSub) {
                $next = $panels[$foundAt + 1] ?? null;
                if ($next !== null && ($next['parent'] ?? -1) === $idx) {
                    // Correct submenu already open; prune anything deeper.
                    if (count($panels) > $foundAt + 2) {
                        $menu['submenus'] = array_slice($menu['submenus'], 0, $foundAt + 1);
                        $changed = true;
                    }
                } else {
                    // Replace everything below this row with a fresh submenu.
                    $newPanel = $this->makeSubmenuPanel($id, $foundAt, $idx, $panel);
                    $menu['submenus'] = array_slice($menu['submenus'], 0, $foundAt);
                    if ($newPanel !== []) {
                        $menu['submenus'][] = $newPanel;
                    }
                    $changed = true;
                }
            } else {
                // Close deeper submenus below this panel.
                if (count($panels) > $foundAt + 1) {
                    $menu['submenus'] = array_slice($menu['submenus'], 0, $foundAt);
                    $changed = true;
                }
            }
            // Write the (possibly updated) hover back into the right panel.
            if ($foundAt === 0) {
                $menu['hover'] = $panel['hover'];
            } else {
                $menu['submenus'][$foundAt - 1]['hover'] = $panel['hover'];
            }
            $this->contextMenus[$id] = $menu;
        }
        if ($changed) {
            $this->requestRedraw();
        }
    }

    /** Dispatch a gesture (e.g. 'swipe') recognized on an element. */
    private function contextMenuSize(array $items): array
    {
        $hasGutter = false;
        foreach ($items as $it) {
            if (isset($it['icon']) || isset($it['checked'])) {
                $hasGutter = true;
                break;
            }
        }
        $gutter = $hasGutter ? 22 : 0;
        $w = 0;
        foreach ($items as $it) {
            $titleW = mb_strlen((string)($it['title'] ?? '')) * 7 + 24 + $gutter;
            if (isset($it['preview'])) {
                $titleW = max($titleW, 260 + $gutter);
            }
            $w = max($w, $titleW);
        }
        $h = count($items) * self::MENU_ROW_H + 2 * self::MENU_PAD;
        return [(float)$w, (float)$h];
    }

    private function clampMenuPos(float $x, float $y, float $mw, float $mh): array
    {
        $ww = $this->root ? (int)$this->root->prop('width', 320) : 320;
        $wh = $this->root ? (int)$this->root->prop('height', 240) : 240;
        $cx = min(max($x, 0.0), max(0.0, $ww - $mw));
        $cy = min(max($y, 0.0), max(0.0, $wh - $mh));
        return [(float)$cx, (float)$cy];
    }

    /** Handle a pointer-down that concerns an open (or to-be-opened) menu.
     *  Returns true if the event was consumed by the menu. */
    private function handleContextMenuPointer(float $x, float $y, int $button): bool
    {
        if ($this->contextMenus !== []) {
            $hit = $this->hitContextMenu($x, $y);
            if ($hit !== null) {
                $menu = $this->contextMenus[$hit['id']];
                $panel = $hit['depth'] === 0 ? $menu : $menu['submenus'][$hit['depth'] - 1];
                $item = $panel['items'][$hit['index']] ?? null;
                if (is_array($item) && (isset($item['action']) || isset($item['msg']))) {
                    $this->runContextMenuItem($hit['id'], $hit['index'], $hit['depth']);
                    unset($this->contextMenus[$hit['id']]);
                    $this->requestRedraw();
                    return true;
                }
                // A preview / submenu-parent row (or empty space within the menu)
                // keeps it open.
                return true;
            }
            // Click outside any open menu dismisses it; a right-click is consumed,
            // a left-click falls through to the widgets underneath.
            $this->closeContextMenus();
            $this->requestRedraw();
            return $button === self::MB_RIGHT;
        }
        if ($button === self::MB_RIGHT) {
            $node = Layout::hitTest($this->layout, $x, $y);
            if ($node !== null) {
                $id = $node->el->prop('id');
                if ($id !== null) {
                    $sid = (string)$id;
                    if (in_array($node->type, ['input', 'textarea', 'search'], true)) {
                        $this->applyFocus($sid);
                        $this->openContextMenu($sid, $x, $y, $this->editMenuItems());
                    } elseif ($node->el->prop('contextMenu') !== null) {
                        $this->openContextMenu($sid, $x, $y);
                    }
                }
            }
            return true;
        }
        return false;
    }

    private function hitContextMenu(float $x, float $y): ?array
    {
        foreach ($this->contextMenus as $id => $menu) {
            $panels = array_merge([$menu], $menu['submenus']);
            for ($k = count($panels) - 1; $k >= 0; $k--) {
                $p = $panels[$k];
                if ($x >= $p['x'] && $x <= $p['x'] + $p['w']
                    && $y >= $p['y'] && $y <= $p['y'] + $p['h']) {
                    $idx = $this->rowIndexAt($p, $x, $y);
                    if ($idx >= 0 && $idx < count($p['items'])) {
                        return ['id' => $id, 'index' => $idx, 'depth' => $k];
                    }
                    return null;
                }
            }
        }
        return null;
    }

    private function runContextMenuItem(string $id, int $index, int $depth = 0): void
    {
        $menu = $this->contextMenus[$id] ?? null;
        if ($menu === null) {
            return;
        }
        $panel = $depth === 0 ? $menu : ($menu['submenus'][$depth - 1] ?? null);
        if ($panel === null) {
            return;
        }
        $item = $panel['items'][$index] ?? null;
        if (!is_array($item)) {
            return;
        }
        if (isset($item['action'])) {
            $this->runEditAction($id, (string)$item['action']);
        } elseif (isset($item['msg'])) {
            ($this->dispatch)((string)$item['msg']);
        }
    }

    private function runEditAction(string $id, string $action): void
    {
        switch ($action) {
            case 'undo':      $this->undoEdit($id); break;
            case 'redo':      $this->redoEdit($id); break;
            case 'cut':       $this->cut($id); break;
            case 'copy':      $this->copy($id); break;
            case 'paste':     $this->paste($id); break;
            case 'selectAll': $this->selectAll($id); break;
        }
    }

    /** Built-in edit menu for text fields (Undo/Redo/Cut/Copy/Paste/Select All). */
    private function editMenuItems(): array
    {
        return [
            ['title' => 'Clipboard', 'preview' => 'clipboard'],
            ['title' => 'Undo',       'action' => 'undo'],
            ['title' => 'Redo',       'action' => 'redo'],
            ['title' => 'Cut',        'action' => 'cut'],
            ['title' => 'Copy',       'action' => 'copy'],
            ['title' => 'Paste',      'action' => 'paste'],
            ['title' => 'Select All', 'action' => 'selectAll'],
        ];
    }

    private function drawContextMenu($cr, array $menu): void
    {
        $this->drawMenu($cr, $menu, $menu['hover'] ?? -1);
        foreach ($menu['submenus'] as $sub) {
            $this->drawMenu($cr, $sub, $sub['hover'] ?? -1);
        }
    }

    private function drawMenu($cr, array $menu, int $hover): void
    {
        $x = $menu['x'];
        $y = $menu['y'];
        $w = $menu['w'];
        $h = $menu['h'];
        Cairo::fillRect($cr, $x, $y, $w, $h, ...$this->col('surface'));
        Cairo::strokeRect($cr, $x, $y, $w, $h, ...$this->col('border'));
        $hasGutter = false;
        foreach ($menu['items'] as $it) {
            if (isset($it['icon']) || isset($it['checked'])) {
                $hasGutter = true;
                break;
            }
        }
        $gutter = $hasGutter ? 22 : 0;
        $titleX = $x + 12 + $gutter;
        foreach ($menu['items'] as $i => $it) {
            $ry = $y + self::MENU_PAD + $i * self::MENU_ROW_H;
            if ($i === $hover) {
                Cairo::fillRect($cr, $x, $ry, $w, self::MENU_ROW_H, ...$this->col('accentSoft'));
            }
            if ($gutter > 0) {
                if (isset($it['checked']) && $it['checked']) {
                    Cairo::text($cr, $x + 10, $ry + self::MENU_ROW_H - 8, '✓', 13, ...$this->col('text'));
                } elseif (isset($it['icon'])) {
                    Cairo::text($cr, $x + 10, $ry + self::MENU_ROW_H - 8, (string)$it['icon'], 13, ...$this->col('text'));
                }
            }
            $title = (string)($it['title'] ?? '');
            if (isset($it['preview']) && $it['preview'] === 'clipboard') {
                $pv = $this->menuPreviewText($it);
                Cairo::text($cr, $titleX, $ry + self::MENU_ROW_H - 8, $title, 13, ...$this->col('text'));
                if ($pv !== null) {
                    $tw = mb_strlen($title) * 7;
                    $avail = (int)($w - $gutter - $tw - 12 - 8 - 16);
                    $label = '“' . $pv . '”';
                    $maxChars = max(1, (int)($avail / 7));
                    if (mb_strlen($label) > $maxChars) {
                        $label = mb_substr($label, 0, $maxChars) . '…';
                    }
                    Cairo::text($cr, $titleX + $tw + 8, $ry + self::MENU_ROW_H - 8, $label, 11, ...$this->col('textMuted'));
                }
                continue;
            }
            Cairo::text($cr, $titleX, $ry + self::MENU_ROW_H - 8, $title, 13, ...$this->col('text'));
            if (isset($it['submenu'])) {
                Cairo::text($cr, $x + $w - 16, $ry + self::MENU_ROW_H - 8, '›', 13, ...$this->col('textMuted'));
            }
        }
    }

    public function dispatchGesture(string $id, string $type): void
    {
        $node = $this->findNodeById($id);
        if ($node === null) {
            return;
        }
        if (($node->el->prop('gesture') ?? '') !== $type) {
            return;
        }
        $msg = $node->el->prop('onGesture');
        if (is_string($msg) && $msg !== '') {
            ($this->dispatch)($msg, $type);
        }
    }

    public function mount(Element $root, \Closure $dispatch): void
    {
        $this->animStart = [];
        $this->animStates = [];
        $this->root = $root;
        $this->dispatch = $dispatch;

        $title = (string) $root->prop('title', 'App');
        $w = (int) $root->prop('width', 320);
        $h = (int) $root->prop('height', 240);
        $this->host = Phpc::call($this->ffi, 'ui3_host_create', [$title, $w, $h, $this->headless ? 1 : 0]);

        $draw = Phpc::callback($this->ffi, function ($ctx, $host, $cr) {
            try {
                $this->paint($cr);
            } catch (\Throwable $e) {
                fwrite(STDERR, "CANVAS DRAW ERR: {$e}\n");
            }
        }, 'void(*)(void*,void*,void*)');
        $ev = Phpc::callback($this->ffi, function ($ctx, int $kind, float $x, float $y, float $data, $text) {
            try {
                // FFI passes char* as FFI\CData; read it back as a PHP string.
                $text = $text !== null ? \FFI::string($text) : null;
                $this->onEvent($kind, $x, $y, $data, $text);
            } catch (\Throwable $e) {
                fwrite(STDERR, "CANVAS EVENT ERR: {$e}\n");
            }
        }, 'void(*)(void*,int,double,double,double,char*)');

        $this->keep = [$draw, $ev];
        Phpc::call($this->ffi, 'ui3_host_set_draw_cb', [$this->host, $draw->raw(), null]);
        Phpc::call($this->ffi, 'ui3_host_set_event_cb', [$this->host, $ev->raw(), null]);
    }

    public function update(Element $root): void
    {
        $this->animStart = [];
        $this->animStates = [];
        $this->root = $root;
        $this->layout = Layout::compute($root);
        if ($this->host) {
            Phpc::call($this->ffi, 'ui3_host_request_redraw', [$this->host]);
        }
    }

    public function step(): int
    {
        return (int) Phpc::call($this->ffi, 'ui3_host_step', [$this->host]);
    }

    public function run(): void
    {
        if ($this->headless) {
            // Offscreen: present once to exercise the draw path, then return so
            // automation / CLI can drive the app from PHP without blocking.
            Phpc::call($this->ffi, 'ui3_host_present', [$this->host]);
            return;
        }
        Phpc::call($this->ffi, 'ui3_host_run', [$this->host]);
    }

    public function quit(): void
    {
        if ($this->host) {
            Phpc::call($this->ffi, 'ui3_host_quit', [$this->host]);
        }
    }

    /** Inject a pointer event (headless automation). */
    public function injectPointer(float $x, float $y, bool $down, int $button = self::MB_LEFT): void
    {
        Phpc::call($this->ffi, 'ui3_host_inject_pointer', [$this->host, $x, $y, $down ? 1 : 0, $button]);
    }

    /** Inject a keystroke (headless automation). Routes to the focused field. */
    public function injectKey(string $text): void
    {
        Phpc::call($this->ffi, 'ui3_host_inject_key', [$this->host, $text]);
    }

    /**
     * Feed a key by raw scancode + modifiers — the exact same translation a
     * real Cocoa keyDown uses (#5c). Lets headless automation exercise the
     * native key path (Tab/Shift+Tab/arrows/Enter/Backspace) without a display.
     */
    public function injectRawKey(int $keycode, bool $shift = false, string $chars = ''): void
    {
        Phpc::call($this->ffi, 'ui3_host_inject_raw_key', [$this->host, $keycode, $shift ? 1 : 0, $chars]);
    }

    /**
     * Post a key the SAME way the OS would: headless -> inject queue; real window
     * -> a synthesized native key event through the platform key path (e.g. Cocoa
     * window.keyDown:). This lets automation drive AND verify the real key path.
     */
    public function postKey(int $keycode, bool $shift, string $chars): void
    {
        Phpc::call($this->ffi, 'ui3_host_post_key', [$this->host, $keycode, $shift ? 1 : 0, $chars]);
    }

    /** Focus a widget by id (real-window focus behavior). */
    public function focus(string $id): void
    {
        $this->applyFocus($id);
    }

    public function focusedId(): ?string
    {
        return $this->focusId;
    }

    /** Reset a field's edit buffer to empty (used by input() to set a value). */
    public function resetField(string $id): void
    {
        $this->fields[$id] = ['text' => '', 'cursor' => 0, 'sel' => 0, 'undo' => [], 'redo' => []];
    }

    /**
     * Commit a whole new value to a text field in one step, WITHOUT going through
     * synthesized key events. Updates the edit buffer and emits the onInput message
     * with the entire string — the same final step editText() performs after a
     * keystroke. Lets automation/AI set field text deterministically, with no
     * dependency on the OS key path (which is unreliable on some platforms).
     *
     * @throws \RuntimeException when the id is not a text field.
     */
    public function setFieldText(string $id, string $text): void
    {
        $node = $this->findNodeById($id);
        if ($node === null || !in_array($node->type, ['input', 'textarea', 'search'], true)) {
            throw new \RuntimeException("no text field with id {$id}");
        }
        $this->seedField($id);
        $this->fields[$id]['text'] = $text;
        $this->fields[$id]['cursor'] = mb_strlen($text);
        $this->fields[$id]['sel'] = mb_strlen($text);
        $this->fields[$id]['undo'] = [];
        $this->fields[$id]['redo'] = [];
        $h = $node->el->prop('onInput');
        if (is_string($h) && $h !== '' && $this->dispatch !== null) {
            ($this->dispatch)($h, $text);
        }
    }

    /** Current text of a field's edit buffer (real editing state). */
    public function fieldText(string $id): string
    {
        $this->seedField($id);
        return $this->fields[$id]['text'];
    }

    /** Current insert position within a field's edit buffer. */
    public function fieldCursor(string $id): int
    {
        $this->seedField($id);
        return $this->fields[$id]['cursor'];
    }

    /**
     * What an input/textarea actually shows on screen: the live edit buffer
     * (what the user has typed) takes priority, falling back to the element's
     * model-bound value, then the placeholder. Mirrors drawNode() so automation
     * and tests can assert on the visible text without a pixel capture.
     */
    public function fieldDisplayText(string $id): string
    {
        $node = $this->findNodeById($id);
        return $node === null ? '' : $this->displayTextFor($node);
    }

    /** [start, end] character range currently selected (start <= end). */
    public function fieldSelectionRange(string $id): array
    {
        $this->seedField($id);
        $b = $this->fields[$id];
        return [min($b['cursor'], $b['sel']), max($b['cursor'], $b['sel'])];
    }

    /** Native clipboard is reachable only for a real (displayed) window. */
    private function nativeClipboard(): bool
    {
        return $this->host !== null && !$this->isHeadless();
    }

    /** Read a C char* return into a PHP string (null-safe for cancelled dialogs). */
    private function cstr(mixed $ptr): string
    {
        if ($ptr === null) {
            return '';
        }
        if ($ptr instanceof \FFI\CData) {
            return \FFI::isNull($ptr) ? '' : \FFI::string($ptr);
        }
        return (string)$ptr;
    }

    public function clipboard(): string
    {
        if ($this->nativeClipboard()) {
            try {
                $this->clipboard = $this->cstr(
                    Phpc::call($this->ffi, 'ui3_host_get_clipboard_text', [$this->host])
                );
            } catch (\Throwable) {
                // keep the in-memory mirror
            }
        }
        return $this->clipboard;
    }

    public function setClipboard(string $s): void
    {
        $this->clipboard = $s;
        if ($this->nativeClipboard()) {
            try {
                Phpc::call($this->ffi, 'ui3_host_set_clipboard_text', [$this->host, $s]);
            } catch (\Throwable) {
                // in-memory mirror already updated
            }
        }
    }

    /**
     * Open a native file chooser. Returns the chosen path, or null if the dialog
     * is unavailable (headless) or was cancelled.
     *
     * @param string|null $filters Filter spec: "png,jpg" or
     *     "Images:png,jpg;Text:txt,md" (labeled groups; leading dots optional).
     *     An "All Files" entry is always appended.
     */
    public function openFile(?string $filters = null): ?string
    {
        if ($this->host === null || $this->isHeadless()) {
            return null;
        }
        try {
            $path = $this->cstr(Phpc::call($this->ffi, 'ui3_host_open_file', [$this->host, $filters ?? '']));
        } catch (\Throwable) {
            return null;
        }
        return $path === '' ? null : $path;
    }

    /**
     * Open a native save dialog. Returns the chosen path, or null if unavailable
     * (headless) or cancelled.
     *
     * @param string|null $defext Default extension (e.g. "png"), used for the
     *     dialog filter and the default name "untitled.<defext>".
     */
    public function saveFile(?string $defext = null): ?string
    {
        if ($this->host === null || $this->isHeadless()) {
            return null;
        }
        try {
            $path = $this->cstr(Phpc::call($this->ffi, 'ui3_host_save_file', [$this->host, $defext ?? '']));
        } catch (\Throwable) {
            return null;
        }
        return $path === '' ? null : $path;
    }

    /** Cut the current selection of a field (defaults to the focused field) to the clipboard. */
    public function cut(?string $id = null): void
    {
        $id ??= $this->focusId ?? '';
        if ($id === '') {
            return;
        }
        $node = $this->findNodeById($id);
        if ($node === null) {
            return;
        }
        $this->seedField($id);
        $buf = &$this->fields[$id];
        if ($buf['sel'] === $buf['cursor']) {
            return;
        }
        $this->setClipboard(mb_substr($buf['text'], min($buf['cursor'], $buf['sel']), abs($buf['cursor'] - $buf['sel'])));
        $this->pushUndo($buf);
        $this->deleteSelection($buf);
        $this->emitInput($node, $buf);
    }

    /** Copy the current selection of a field to the clipboard. */
    public function copy(?string $id = null): void
    {
        $id ??= $this->focusId ?? '';
        if ($id === '') {
            return;
        }
        $this->seedField($id);
        $buf = $this->fields[$id];
        if ($buf['sel'] === $buf['cursor']) {
            return;
        }
        $this->setClipboard(mb_substr($buf['text'], min($buf['cursor'], $buf['sel']), abs($buf['cursor'] - $buf['sel'])));
    }

    /** Paste the clipboard contents over the selection (or at the caret) of a field. */
    public function paste(?string $id = null): void
    {
        $id ??= $this->focusId ?? '';
        $text = $this->clipboard();
        if ($id === '' || $text === '') {
            return;
        }
        $node = $this->findNodeById($id);
        if ($node === null) {
            return;
        }
        $this->seedField($id);
        $buf = &$this->fields[$id];
        $this->pushUndo($buf);
        $this->replaceSelection($buf, $text);
        $this->emitInput($node, $buf);
    }

    /** Select the entire contents of a field. */
    public function selectAll(?string $id = null): void
    {
        $id ??= $this->focusId ?? '';
        if ($id === '') {
            return;
        }
        $this->seedField($id);
        $buf = &$this->fields[$id];
        $buf['sel'] = 0;
        $buf['cursor'] = mb_strlen($buf['text']);
        $this->requestRedraw();
    }

    public function undoEdit(?string $id = null): void
    {
        $id ??= $this->focusId ?? '';
        if ($id === '') {
            return;
        }
        $node = $this->findNodeById($id);
        if ($node === null) {
            return;
        }
        $this->seedField($id);
        $buf = &$this->fields[$id];
        $this->doUndo($buf);
        $this->emitInput($node, $buf);
    }

    public function redoEdit(?string $id = null): void
    {
        $id ??= $this->focusId ?? '';
        if ($id === '') {
            return;
        }
        $node = $this->findNodeById($id);
        if ($node === null) {
            return;
        }
        $this->seedField($id);
        $buf = &$this->fields[$id];
        $this->doRedo($buf);
        $this->emitInput($node, $buf);
    }

    /** Visible text for a field node: edit buffer > element value > placeholder. */
    private function displayTextFor(Node $node): string
    {
        $id = (string) $node->el->prop('id', '');
        $buf = ($id !== '' && isset($this->fields[$id]))
            ? $this->fields[$id]['text']
            : (string) $node->el->prop('text', '');
        return $buf !== '' ? $buf : (string) $node->el->prop('placeholder', '');
    }

    /**
     * Draw an IME composition candidate preview after $committed text inside a
     * field, with an accent colour and an underline (like a real IME preview).
     */
    private function drawComposition($cr, Element $el, string $committed, float $tx, float $ty, float $availW): void
    {
        $id = (string) $el->prop('id', '');
        $comp = $id !== '' && isset($this->composition[$id]) ? $this->composition[$id] : null;
        if ($comp === null || ($comp['text'] ?? '') === '') {
            return;
        }
        $e = Cairo::textExtents($cr, $committed);
        $cx = $tx + $e['w'];
        Cairo::text($cr, $cx, $ty, $comp['text'], 13, ...$this->col('accent'));
        $uw = (int) Cairo::textExtents($cr, $comp['text'])['w'];
        if ($uw > 0) {
            Cairo::fillRect($cr, $cx, $ty + 2, $uw, 1, ...$this->col('accent'));
        }
    }

    /** Current highlight index for a list/select (real-window browse state). */
    public function highlightIndex(string $id): int
    {
        $this->seedHighlight($id);
        return $this->highlights[$id];
    }

    public function root(): ?Element
    {
        return $this->root;
    }

    /** The laid-out nodes (real canvas geometry) for the current Element tree. */
    public function layout(): array
    {
        if ($this->root !== null) {
            $this->layout = Layout::compute($this->root);
        }
        return $this->layout;
    }

    private function paint($cr): void
    {
        // The host hands back its cairo_t* as a void* (cross-FFI scope); bridge
        // it into the Cairo wrapper's scope so its struct type matches.
        $cr = Cairo::ffi()->cast('cairo_t*', $cr);
        // Advance the animation clock (wall-clock for real windows, frozen for tests).
        $this->clock = $this->now();
        $this->framesDrawn++;
        $w = $this->root ? (int) $this->root->prop('width', 320) : 320;
        $h = $this->root ? (int) $this->root->prop('height', 240) : 240;
        Cairo::fillRect($cr, 0, 0, $w, $h, ...$this->col('bg'));
        if (!$this->root) {
            return;
        }
        // Rubber-band: decay elastic overshoot toward 0 each frame so a past-edge
        // scroll springs back. Then merge the damped overshoot into the offsets
        // used for layout, so the content visually overshoots and settles.
        foreach ($this->scrollElastic as $eid => $e) {
            $e2 = $e * 0.82;
            if (abs($e2) < 0.5) {
                unset($this->scrollElastic[$eid]);
            } else {
                $this->scrollElastic[$eid] = $e2;
            }
        }
        $offsets = [];
        foreach ($this->scrollOverrides as $eid => $off) {
            $e = $this->scrollElastic[$eid] ?? 0.0;
            $offsets[$eid] = (int) round($off + $e * self::SCROLL_ELASTIC_DAMP);
        }
        $this->layout = Layout::compute($this->root, $offsets);
        // Clip stack: each `scroll` node pushes its viewport rect (drawn unclipped
        // as the frame), its content is painted clipped, and the `scroll_end`
        // sentinel pops it. LIFO handles nested scrolls.
        $clipStack = [];
        foreach ($this->layout as $n) {
            if ($n->type === 'scroll_end') {
                $sc = array_pop($clipStack);
                if ($sc !== null) {
                    $this->drawScrollbar($cr, $sc);
                }
                continue;
            }
            $clip = $clipStack !== [] ? $clipStack[count($clipStack) - 1]['rect'] : null;
            if ($clip !== null) {
                Cairo::save($cr);
                Cairo::clip($cr, $clip[0], $clip[1], $clip[2], $clip[3]);
            }
            $this->drawNode($cr, $n);
            if ($clip !== null) {
                Cairo::restore($cr);
            }
            if ($n->type === 'scroll') {
                $id = $n->el->prop('id');
                $sid = $id !== null ? (string) $id : '';
                $clipStack[] = [
                    'rect'     => [$n->x, $n->y, $n->w, $n->h],
                    'node'     => $n,
                    'contentH' => $sid !== '' ? Layout::scrollContentHeight($sid) : $n->h,
                    'off'      => $sid !== '' ? $this->effectiveScrollOffset($sid) : 0,
                ];
            }
        }
        // Overlay scrollbars for virtual list controls, drawn last so they sit
        // on top of their windowed rows (lists have no clip sentinel).
        foreach ($this->layout as $n) {
            if ($n->type !== 'list' || !(bool) $n->el->prop('virtual', false)) {
                continue;
            }
            $id = $n->el->prop('id');
            if ($id === null) {
                continue;
            }
            $sid = (string) $id;
            $this->paintScrollbar($cr, $n, Layout::scrollContentHeight($sid), $this->effectiveScrollOffset($sid));
        }
        // Context menus drawn last so they overlay everything else.
        foreach ($this->contextMenus as $menu) {
            $this->drawContextMenu($cr, $menu);
        }
        // Keep the native frame loop alive while animations or scroll physics
        // (rubber-band / scrollbar fade) are still in flight.
        $scrollAnimating = $this->scrollElastic !== []
            || $this->clock < $this->scrollbarVisibleUntil + self::SCROLLBAR_FADE;
        if ($this->host && ($this->isAnimating() || $scrollAnimating)) {
            Phpc::call($this->ffi, 'ui3_host_request_redraw', [$this->host]);
        }
    }

    private function onEvent(int $kind, float $x, float $y, float $data, ?string $text): void
    {
        if ($this->layout === [] && $this->root) {
            $this->layout = Layout::compute($this->root);
        }
        if ($kind === self::KEY) {
            $this->onKey($text);
            return;
        }
        if ($kind === self::WHEEL) {
            // data > 0 means "scroll down" (viewport offset increases). The host
            // normalizes each platform's wheel delta to pixels before calling.
            $id = $this->scrollContainerAt($x, $y);
            if ($id !== null) {
                $this->scrollBy($id, (int) $data);
            }
            return;
        }
        // Drag (MOVE/UP) is driven by the in-progress drag, not by hit-testing.
        if ($kind === self::POINTER_MOVE && $this->contextMenus !== []) {
            $this->updateMenuHover($x, $y);
            return;
        }

        if ($kind === self::POINTER_MOVE || $kind === self::POINTER_UP) {
            // Native overlay scrollbars reveal on hover over a scroll container.
            if ($kind === self::POINTER_MOVE) {
                $sid = $this->scrollContainerAt($x, $y);
                if ($sid !== null) {
                    $this->scrollbarVisibleUntil = $this->now() + self::SCROLLBAR_IDLE;
                    $this->requestRedraw();
                }
            }
            $this->onPointerDrag($kind, $x, $y);
            return;
        }
        if ($kind !== self::POINTER_DOWN) {
            return;
        }
        if ($this->handleContextMenuPointer($x, $y, (int)$data)) {
            return;
        }
        // An expanded dropdown captures clicks inside its popup.
        foreach ($this->expanded as $eid => $on) {
            if (!$on) {
                continue;
            }
            $en = $this->findNodeById($eid);
            if ($en === null) {
                continue;
            }
            $opts = $en->el->prop('options', []);
            $popTop = $en->y + $en->h;
            $popBottom = $popTop + count($opts) * 20;
            if ($x >= $en->x && $x <= $en->x + $en->w && $y >= $popTop && $y <= $popBottom) {
                $this->pickSelectOption($en, $eid, $y);
                return;
            }
        }
        // Grab the scrollbar (thumb drag or track jump) before content
        // hit-testing, since the overlay bar sits on top of the content.
        if ($this->tryBeginScrollbarDrag($x, $y)) {
            return;
        }
        $node = Layout::hitTest($this->layout, $x, $y);
        if (!$node || !$this->dispatch) {
            return;
        }
        // Remember which scroll container the pointer landed in, so arrow keys
        // can scroll it later (see onKey).
        $this->activeScrollId = $this->scrollContainerAt($x, $y);
        // Clicking a focusable widget focuses it, like a real window.
        if (in_array($node->type, self::FOCUSABLE, true)
            && ($id = $node->el->prop('id')) !== null) {
            $this->applyFocus((string) $id);
        }
        $this->fire($node, $x, $y);
    }

    /** Return the id of the innermost scroll container containing (x, y). */
    private function scrollContainerAt(float $x, float $y): ?string
    {
        $found = null;
        foreach ($this->layout as $n) {
            $isScroll = $n->type === 'scroll'
                || ($n->type === 'list' && (bool) $n->el->prop('virtual', false));
            if (!$isScroll) {
                continue;
            }
            $id = $n->el->prop('id');
            if ($id === null) {
                continue;
            }
            if ($x >= $n->x && $x <= $n->x + $n->w && $y >= $n->y && $y <= $n->y + $n->h) {
                $found = (string) $id; // later (inner) scrolls win on overlap
            }
        }
        return $found;
    }

    /** Route a keystroke: Tab, list/select browsing, or text editing. */
    private function onKey(?string $text): void
    {
        if ($text === null || $this->dispatch === null) {
            return;
        }
        // Arrow Up/Down scroll the active scroll container (set on pointer-down).
        if (($text === self::KEY_UP || $text === self::KEY_DOWN) && $this->activeScrollId !== null) {
            $this->scrollBy($this->activeScrollId, $text === self::KEY_DOWN ? 40 : -40);
            return;
        }
        // Escape closes any open context menu first, then expanded dropdowns.
        if ($text === self::KEY_ESC) {
            if ($this->contextMenus !== []) {
                $this->closeContextMenus();
                $this->requestRedraw();
                return;
            }
            $changed = false;
            foreach ($this->expanded as $eid => $on) {
                if ($on) {
                    $this->expanded[$eid] = false;
                    $changed = true;
                }
            }
            if ($changed && $this->host) {
                Phpc::call($this->ffi, 'ui3_host_request_redraw', [$this->host]);
            }
            return;
        }
        if ($text === 'Tab') {
            $this->moveFocus(1);
            return;
        }
        if ($text === 'Shift+Tab') {
            $this->moveFocus(-1);
            return;
        }
        if ($this->focusId === null) {
            return;
        }
        $node = null;
        foreach ($this->layout as $n) {
            if (($n->el->prop('id') ?? null) === $this->focusId) {
                $node = $n;
                break;
            }
        }
        if ($node === null) {
            return;
        }
        if (in_array($node->type, self::NAVIGABLE, true)) {
            $this->navigate($node, $text);
            return;
        }
        if (in_array($node->type, ['input', 'textarea', 'search'], true)) {
            $this->editText($node, $text);
            return;
        }
    }

    /** Edit a text control: maintain the per-field buffer (see #5a). */
    private function editText(Node $node, string $text): void
    {
        $id = (string) $node->el->prop('id', '');
        $this->seedField($id);
        $buf = &$this->fields[$id];

        // ---- Ctrl+ shortcuts (host emits "Ctrl+<key>" on macOS; see P0.1) ----
        if (str_starts_with($text, 'Ctrl+')) {
            $lower = strtolower(substr($text, 5));
            if ($lower === 'a') {                 // select all
                $buf['sel'] = 0;
                $buf['cursor'] = mb_strlen($buf['text']);
                $this->requestRedraw();
                return;
            }
            if ($lower === 'c') {                 // copy
                if ($buf['sel'] !== $buf['cursor']) {
                    $this->setClipboard(mb_substr($buf['text'], min($buf['cursor'], $buf['sel']), abs($buf['cursor'] - $buf['sel'])));
                }
                return;
            }
            if ($lower === 'x') {                 // cut
                if ($buf['sel'] !== $buf['cursor']) {
                    $this->setClipboard(mb_substr($buf['text'], min($buf['cursor'], $buf['sel']), abs($buf['cursor'] - $buf['sel'])));
                    $this->pushUndo($buf);
                    $this->deleteSelection($buf);
                    $this->emitInput($node, $buf);
                }
                return;
            }
            if ($lower === 'v') {                 // paste
                if ($this->clipboard !== '') {
                    $this->pushUndo($buf);
                    $this->replaceSelection($buf, $this->clipboard);
                    $this->emitInput($node, $buf);
                }
                return;
            }
            if ($lower === 'z') {                 // undo
                $this->doUndo($buf);
                $this->emitInput($node, $buf);
                return;
            }
            if ($lower === 'y' || $lower === 'shift+z') { // redo
                $this->doRedo($buf);
                $this->emitInput($node, $buf);
                return;
            }
            return; // unknown Ctrl combo: ignore
        }

        $len = mb_strlen($buf['text']);
        $c = $buf['cursor'];
        switch ($text) {
            case self::KEY_LEFT:
                $buf['cursor'] = max(0, $c - 1);
                $buf['sel'] = $buf['cursor'];
                break;
            case self::KEY_RIGHT:
                $buf['cursor'] = min($len, $c + 1);
                $buf['sel'] = $buf['cursor'];
                break;
            case self::KEY_SHIFT_LEFT:
                $buf['cursor'] = max(0, $c - 1);
                break;
            case self::KEY_SHIFT_RIGHT:
                $buf['cursor'] = min($len, $c + 1);
                break;
            case self::KEY_HOME:
                $buf['cursor'] = 0;
                $buf['sel'] = 0;
                break;
            case self::KEY_END:
                $buf['cursor'] = $len;
                $buf['sel'] = $len;
                break;
            case self::KEY_DEL:
                $this->pushUndo($buf);
                $this->deleteAt($buf, false);
                $this->emitInput($node, $buf);
                break;
            case "\x08":                        // Backspace
                $this->pushUndo($buf);
                $this->deleteAt($buf, true);
                $this->emitInput($node, $buf);
                break;
            default:
                $ins = mb_strlen($text);
                if ($ins >= 1 && $text !== "\r" && ord($text[0]) >= 0x20
                    && ($text !== "\n" || $node->type === 'textarea')) {
                    $this->pushUndo($buf);
                    if ($buf['sel'] !== $buf['cursor']) {
                        $this->deleteSelection($buf);
                    }
                    $pos = $buf['cursor'];
                    $buf['text'] = mb_substr($buf['text'], 0, $pos) . $text . mb_substr($buf['text'], $pos);
                    $buf['cursor'] = $pos + mb_strlen($text);
                    $buf['sel'] = $buf['cursor'];
                    $this->emitInput($node, $buf);
                }
                break;
        }
        $this->requestRedraw();
    }

    private function pushUndo(array &$buf): void
    {
        $buf['undo'][] = ['text' => $buf['text'], 'cursor' => $buf['cursor'], 'sel' => $buf['sel']];
        if (count($buf['undo']) > 200) {
            array_shift($buf['undo']);
        }
        $buf['redo'] = [];
    }

    private function doUndo(array &$buf): void
    {
        if ($buf['undo'] === []) {
            return;
        }
        $buf['redo'][] = ['text' => $buf['text'], 'cursor' => $buf['cursor'], 'sel' => $buf['sel']];
        $prev = array_pop($buf['undo']);
        $buf['text'] = $prev['text'];
        $buf['cursor'] = $prev['cursor'];
        $buf['sel'] = $prev['sel'];
    }

    private function doRedo(array &$buf): void
    {
        if ($buf['redo'] === []) {
            return;
        }
        $buf['undo'][] = ['text' => $buf['text'], 'cursor' => $buf['cursor'], 'sel' => $buf['sel']];
        $next = array_pop($buf['redo']);
        $buf['text'] = $next['text'];
        $buf['cursor'] = $next['cursor'];
        $buf['sel'] = $next['sel'];
    }

    private function deleteSelection(array &$buf): void
    {
        $c = $buf['cursor'];
        $s = $buf['sel'];
        if ($c === $s) {
            return;
        }
        [$a, $b] = $c < $s ? [$c, $s] : [$s, $c];
        $buf['text'] = mb_substr($buf['text'], 0, $a) . mb_substr($buf['text'], $b);
        $buf['cursor'] = $a;
        $buf['sel'] = $a;
    }

    private function deleteAt(array &$buf, bool $backward): void
    {
        $c = $buf['cursor'];
        $s = $buf['sel'];
        if ($s !== $c) {
            $this->deleteSelection($buf);
            return;
        }
        $len = mb_strlen($buf['text']);
        if ($backward) {
            if ($c > 0) {
                $buf['text'] = mb_substr($buf['text'], 0, $c - 1) . mb_substr($buf['text'], $c);
                $buf['cursor'] = $c - 1;
                $buf['sel'] = $c - 1;
            }
        } elseif ($c < $len) {
            $buf['text'] = mb_substr($buf['text'], 0, $c) . mb_substr($buf['text'], $c + 1);
            $buf['sel'] = $c;
        }
    }

    private function replaceSelection(array &$buf, string $ins): void
    {
        if ($buf['sel'] !== $buf['cursor']) {
            $this->deleteSelection($buf);
        }
        $pos = $buf['cursor'];
        $buf['text'] = mb_substr($buf['text'], 0, $pos) . $ins . mb_substr($buf['text'], $pos);
        $buf['cursor'] = $pos + mb_strlen($ins);
        $buf['sel'] = $buf['cursor'];
    }

    private function emitInput(Node $node, array $buf): void
    {
        $h = $node->el->prop('onInput');
        if (is_string($h) && $h !== '' && $this->dispatch !== null) {
            ($this->dispatch)($h, $buf['text']);
        }
        $this->requestRedraw();
    }

    private function caretVisible(): bool
    {
        return ((int) ($this->now() * 2)) % 2 === 0;
    }

    /** Draw an input/textarea/search field's text, selection highlight and blinking caret. */
    private function drawFieldText($cr, Node $n, float $x, float $y, float $w, float $h, int $padX): void
    {
        $id = (string) $n->el->prop('id', '');
        $buf = $this->fields[$id] ?? null;
        $text = $buf !== null ? $buf['text'] : (string) $n->el->prop('text', '');
        if ($n->el->prop('password')) {
            $text = str_repeat('•', mb_strlen($text));
        }
        $show = $text !== '' ? $text : (string) $n->el->prop('placeholder', '');
        $tc = $text !== '' ? $this->col('text') : $this->col('textMuted');

        Cairo::save($cr);
        Cairo::clip($cr, $x - 2, $y - 2, $w + 4, $h + 4);
        if ($buf !== null && $buf['sel'] !== $buf['cursor']) {
            $this->drawFieldSelection($cr, $text, $buf['sel'], $buf['cursor'], $x, $y, $padX);
        }
        Cairo::text($cr, $x, $y + 12, $show, 13, $tc[0], $tc[1], $tc[2]);
        if ($buf !== null) {
            $this->drawComposition($cr, $n->el, $text, $x, $y + 12, $w);
        }
        if ($buf !== null && $this->caretVisible()) {
            [$cx, $cy] = $this->fieldCaretXY($cr, $text, $buf['cursor'], $x, $y, $padX);
            Cairo::fillRect($cr, $cx, $cy, 1.2, 16, $tc[0], $tc[1], $tc[2]);
        }
        Cairo::restore($cr);
    }

    private function fieldCaretXY($cr, string $text, int $pos, float $x, float $y, int $padX): array
    {
        $lines = explode("\n", mb_substr($text, 0, $pos));
        $i = count($lines) - 1;
        $lineStr = $lines[$i];
        $cx = $x + (float) Cairo::textExtents($cr, $lineStr)['w'];
        $cy = $y + 2 + $i * 18;
        return [$cx, $cy];
    }

    private function drawFieldSelection($cr, string $text, int $sel, int $cur, float $x, float $y, int $padX): void
    {
        if ($cur === $sel) {
            return;
        }
        [$a, $b] = $cur < $sel ? [$cur, $sel] : [$sel, $cur];
        $lineH = 18;
        $lines = explode("\n", $text);
        $pos = 0;
        $col = $this->col('selected');
        foreach ($lines as $i => $line) {
            $lineStart = $pos;
            $lineEnd = $pos + mb_strlen($line);
            $segA = max($a, $lineStart);
            $segB = min($b, $lineEnd);
            if ($segA < $segB) {
                $xa = $x + (float) Cairo::textExtents($cr, mb_substr($line, 0, $segA - $lineStart))['w'];
                $xb = $x + (float) Cairo::textExtents($cr, mb_substr($line, 0, $segB - $lineStart))['w'];
                Cairo::fillRect($cr, $xa, $y + 2 + $i * $lineH, $xb - $xa, $lineH - 4, $col[0], $col[1], $col[2]);
            }
            $pos = $lineEnd + 1;
        }
    }

    /** Browse a list/select with arrows; Enter commits (list) / selects (both). */
    private function navigate(Node $node, string $text): void
    {
        $id = (string) $node->el->prop('id');
        $isList = $node->type === 'list';
        $opts = $isList ? $node->el->prop('items', []) : $node->el->prop('options', []);
        $count = count($opts);
        if ($count === 0) {
            return;
        }
        if (!isset($this->highlights[$id])) {
            $v = (int) $node->el->prop('value', $isList ? -1 : 0);
            $this->highlights[$id] = $v < 0 ? 0 : $v;
        }
        $h = &$this->highlights[$id];
        $commit = false;
        if ($text === self::KEY_DOWN) {
            $h = min($h + 1, $count - 1);
            $commit = !$isList;          // a select commits on arrow, like native
        } elseif ($text === self::KEY_UP) {
            $h = max($h - 1, 0);
            $commit = !$isList;
        } elseif ($text === "\n" || $text === "\r") {
            $commit = true;              // Enter commits the highlight
        } else {
            return;
        }
        if ($commit) {
            $msg = $isList ? $node->el->prop('onSelect') : $node->el->prop('onChange');
            if (is_string($msg) && $msg !== '') {
                ($this->dispatch)($msg, $h);
            }
        }
        if ($this->host) {
            Phpc::call($this->ffi, 'ui3_host_request_redraw', [$this->host]);
        }
    }

    /** Seed a field's edit buffer from its current displayed value. */
    private function seedField(string $id): void
    {
        if (isset($this->fields[$id])) {
            return;
        }
        $text = '';
        foreach ($this->layout as $n) {
            if (($n->el->prop('id') ?? null) === $id
                && in_array($n->type, ['input', 'textarea', 'search'], true)) {
                $text = (string) $n->el->prop('text', '');
                break;
            }
        }
        $this->fields[$id] = [
            'text' => $text,
            'cursor' => mb_strlen($text),
            'sel' => mb_strlen($text),
            'undo' => [],
            'redo' => [],
        ];
    }

    /** Seed a list/select highlight from its current value. */
    private function seedHighlight(string $id): void
    {
        if (isset($this->highlights[$id])) {
            return;
        }
        foreach ($this->layout as $n) {
            if (($n->el->prop('id') ?? null) === $id
                && in_array($n->type, self::NAVIGABLE, true)) {
                $v = (int) $n->el->prop('value', $n->type === 'list' ? -1 : 0);
                $this->highlights[$id] = $v < 0 ? 0 : $v;
                return;
            }
        }
    }

    /** Focus a widget and seed its edit/highlight buffers. */
    private function applyFocus(string $id): void
    {
        $this->focusId = $id;
        $this->seedField($id);
        $this->seedHighlight($id);
        // Repaint so the focus ring shows up immediately (a focus change alone
        // doesn't dispatch a model update, which is what normally triggers redraw).
        if ($this->host) {
            Phpc::call($this->ffi, 'ui3_host_request_redraw', [$this->host]);
        }
    }

    /** Move focus by $dir in the Tab order, wrapping (Shift+Tab = -1). */
    private function moveFocus(int $dir = 1): void
    {
        $order = [];
        foreach ($this->layout as $n) {
            if (in_array($n->type, self::FOCUSABLE, true)
                && ($id = $n->el->prop('id')) !== null) {
                $order[] = (string) $id;
            }
        }
        $count = count($order);
        if ($count === 0) {
            $this->focusId = null;
            return;
        }
        $i = array_search($this->focusId, $order, true);
        if ($i === false) {
            $this->applyFocus($dir > 0 ? $order[0] : $order[$count - 1]);
            return;
        }
        $i = ($i + $dir) % $count;
        if ($i < 0) {
            $i += $count;
        }
        $this->applyFocus($order[$i]);
    }

    private function fire(Node $node, float $x, float $y): void
    {
        $el = $node->el;
        $type = $node->type;
        $dispatch = $this->dispatch;
        if ($type === 'button' && ($msg = $el->prop('onClick'))) {
            $dispatch((string) $msg);
        } elseif ($type === 'checkbox' && ($msg = $el->prop('onChange'))) {
            $dispatch((string) $msg, !$el->prop('checked'));
        } elseif ($type === 'radio' && ($msg = $el->prop('onChange'))) {
            $dispatch((string) $msg, true);
        } elseif ($type === 'slider' && ($msg = $el->prop('onChange'))) {
            $val = $this->sliderValueAt($node, $x);
            $dispatch((string) $msg, $val);
            $this->drag = ['id' => (string) $el->prop('id'), 'type' => 'slider'];
        } elseif ($type === 'select' && ($msg = $el->prop('onChange'))) {
            $id = (string) $el->prop('id');
            $this->expanded[$id] = empty($this->expanded[$id]);
            if ($this->host) {
                Phpc::call($this->ffi, 'ui3_host_request_redraw', [$this->host]);
            }
        } elseif ($type === 'list' && ($msg = $el->prop('onSelect'))) {
            $idx = (int) (($y - ($node->y + 4)) / 20);
            $items = $el->prop('items', []);
            if ($idx >= 0 && $idx < count($items)) {
                $dispatch((string) $msg, $idx);
                $this->highlights[(string) $el->prop('id')] = $idx;
            }
        } elseif ($type === 'iconbutton' && ($msg = $el->prop('onClick'))) {
            $dispatch((string) $msg);
        } elseif ($type === 'toggle' && ($msg = $el->prop('onChange'))) {
            $dispatch((string) $msg, $el->prop('on') ? 0 : 1);
        } elseif ($type === 'segmented' && ($msg = $el->prop('onChange'))) {
            $dispatch((string) $msg, $this->segmentedIndexAt($node, $x));
        } elseif ($type === 'list_item' && ($msg = $el->prop('_onSelect'))) {
            $dispatch((string) $msg, (int) $el->prop('_index', 0));
        } elseif ($type === 'split') {
            $vertical = $el->prop('orientation') === 'vertical';
            $pos = (float) $el->prop('position', 0.5);
            $near = $vertical
                ? abs($y - ($node->y + $pos * $node->h)) <= Layout::DIVIDER
                : abs($x - ($node->x + $pos * $node->w)) <= Layout::DIVIDER;
            if ($near) {
                $this->drag = ['id' => (string) $el->prop('id'), 'type' => 'split', 'vertical' => $vertical];
            }
        }
    }

    private function segmentedIndexAt(Node $node, float $x): int
    {
        $options = $node->el->prop('options', []);
        if ($options === []) {
            return 0;
        }
        $seg = $node->w / count($options);
        return max(0, min(count($options) - 1, (int) (($x - $node->x) / $seg)));
    }

    /**
     * Scrollbar geometry (track + thumb rects) for a scroll container, derived
     * from the same tokens used to paint it. Returns null when the content fits
     * (no scrollbar). Shared by drawScrollbar() and pointer hit-testing so the
     * thumb you see is exactly the one you can grab.
     */
    private function scrollbarGeom(Node $node, int $contentH, int $off): ?array
    {
        $vh = $node->h;
        if ($contentH <= $vh) {
            return null; // content fits; no scrollbar needed
        }
        $thickness = (int) ($this->theme['scrollbarThickness'] ?? 8);
        $trackX = $node->x + $node->w - $thickness - 2;
        $trackY = $node->y + 2;
        $trackH = $vh - 4;
        $maxOff = $contentH - $vh;
        $thumbH = max(16, (int) (($vh / $contentH) * $trackH));
        $thumbY = $trackY + (int) ((($trackH - $thumbH) * $off) / $maxOff);
        return [
            'thickness' => $thickness,
            'trackX'    => $trackX,
            'trackY'    => $trackY,
            'trackH'    => $trackH,
            'thumbH'    => $thumbH,
            'thumbY'    => $thumbY,
            'maxOff'    => $maxOff,
            'vh'        => $vh,
            'contentH'  => $contentH,
        ];
    }

    /**
     * Hit-test a vertical scrollbar against a pointer position. Returns 'thumb'
     * if inside the draggable thumb, 'track' if elsewhere on the bar (a click
     * jumps there), or null if outside the bar entirely.
     */
    private function hitScrollbar(float $x, float $y, array $g): ?string
    {
        if ($x >= $g['trackX'] - 2 && $x <= $g['trackX'] + $g['thickness'] + 2
            && $y >= $g['trackY'] && $y <= $g['trackY'] + $g['trackH']) {
            if ($y >= $g['thumbY'] && $y <= $g['thumbY'] + $g['thumbH']) {
                return 'thumb';
            }
            return 'track';
        }
        return null;
    }

    /** Absolute scroll (clamped, no rubber-band) + onScroll + redraw. */
    private function scrollTo(string $id, int $off): void
    {
        $node = $this->findNodeById($id);
        if ($node === null) {
            return;
        }
        $sid = (string) $node->el->prop('id');
        $contentH = Layout::scrollContentHeight($sid);
        $maxOff = max(0, $contentH - (int) $node->h);
        $target = max(0, min($maxOff, $off));
        $this->scrollOverrides[$id] = $target;
        unset($this->scrollElastic[$id]);
        $this->scrollbarVisibleUntil = $this->now() + self::SCROLLBAR_IDLE;
        $msg = $node->el->prop('onScroll');
        if (is_string($msg) && $msg !== '') {
            ($this->dispatch)($msg, $target);
        }
        $this->requestRedraw();
    }

    /**
     * Begin a scrollbar drag if the pointer lands on a scroll container's bar.
     * Returns true (and arms $this->drag) when it consumes the press, so the
     * caller skips the normal content hit-test / fire.
     */
    private function tryBeginScrollbarDrag(float $x, float $y): bool
    {
        $sid = $this->scrollContainerAt($x, $y);
        if ($sid === null) {
            return false;
        }
        $node = $this->findNodeById($sid);
        if ($node === null) {
            return false;
        }
        $contentH = Layout::scrollContentHeight($sid);
        $g = $this->scrollbarGeom($node, $contentH, $this->effectiveScrollOffset($sid));
        if ($g === null) {
            return false;
        }
        $hit = $this->hitScrollbar($x, $y, $g);
        if ($hit === null) {
            return false;
        }
        if ($hit === 'track') {
            // Click on the track: jump so the click lands mid-thumb, then start
            // dragging from there (matches native track-click behaviour).
            $jump = (int) ((($y - $g['trackY']) * $g['maxOff']) / max(1, $g['trackH'] - $g['thumbH']));
            $this->scrollTo($sid, $jump - (int) ($g['thumbH'] / 2));
            $g = $this->scrollbarGeom($node, $contentH, $this->effectiveScrollOffset($sid));
            if ($g === null) {
                $this->drag = ['type' => 'scrollbar', 'id' => $sid, 'grab' => 0, 'trackY' => 0, 'trackH' => 1, 'thumbH' => 1, 'maxOff' => 1];
                $this->requestRedraw();
                return true;
            }
            $grab = $g['thumbH'] / 2;
        } else {
            $grab = $y - $g['thumbY'];
        }
        $this->drag = [
            'type'    => 'scrollbar',
            'id'      => $sid,
            'grab'    => $grab,
            'trackY'  => $g['trackY'],
            'trackH'  => $g['trackH'],
            'thumbH'  => $g['thumbH'],
            'maxOff'  => $g['maxOff'],
        ];
        $this->scrollbarVisibleUntil = $this->now() + self::SCROLLBAR_IDLE;
        $this->requestRedraw();
        return true;
    }

    /**
     * Draw an overlay scrollbar (faint track + accent thumb) for a scrollable
     * node. Shared by scroll containers (via the scroll_end sentinel) and
     * virtual list controls (post-pass), so the geometry/alpha are identical.
     *
     * @param int $off Pixel scroll offset (matches the scroll-container model).
     */
    private function paintScrollbar($cr, Node $node, int $contentH, int $off): void
    {
        $g = $this->scrollbarGeom($node, $contentH, $off);
        if ($g === null) {
            return; // content fits; no scrollbar needed
        }
        // Overlay scrollbar (native behaviour): fully visible right after a
        // scroll/hover, then fades out once idle. $this->clock is frozen in
        // headless tests so the bar stays visible for assertions.
        $remaining = $this->scrollbarVisibleUntil - $this->clock;
        $alpha = $remaining > 0
            ? 1.0
            : max(0.0, min(1.0, ($remaining + self::SCROLLBAR_FADE) / self::SCROLLBAR_FADE));
        if ($alpha <= 0.01) {
            return;
        }
        $radius = (float) ($this->theme['scrollbarRadius'] ?? 3);
        [$tr, $tg, $tb] = $this->col('scrollbarTrack');
        [$hr, $hg, $hb] = $this->col('scrollbarThumb');
        Cairo::fillRoundedRect($cr, $g['trackX'], $g['trackY'], $g['thickness'], $g['trackH'], $radius, $tr, $tg, $tb, 0.45 * $alpha);
        Cairo::fillRoundedRect($cr, $g['trackX'], $g['thumbY'], $g['thickness'], $g['thumbH'], $radius, $hr, $hg, $hb, 0.9 * $alpha);
    }

    /** Scrollbar for a scroll container (carried by the scroll_end sentinel). */
    private function drawScrollbar($cr, array $sc): void
    {
        $this->paintScrollbar($cr, $sc['node'], (int) $sc['contentH'], (int) $sc['off']);
    }

    private function drawNode($cr, Node $n): void
    {
        $el = $n->el;
        $t = $n->type;
        $x = $n->x; $y = $n->y; $w = $n->w; $h = $n->h;
        $f = Cairo::ffi();

        // ----- animation: interpolate translate / scale / opacity from the clock -----
        // Shared with the headless Reference renderer via Animation::frame().
        $dx = 0; $dy = 0; $scale = 1.0; $alpha = 1.0;
        $anim = $el->prop('anim');
        if (is_array($anim) && $anim !== []) {
            $aid = (string)($el->prop('id') ?? spl_object_id($el));
            if (!isset($this->animStart[$aid])) {
                $this->animStart[$aid] = $this->clock;
            }
            $elapsed = ($this->clock - $this->animStart[$aid]) * 1000.0;
            $st = Animation::frame($anim, $elapsed);
            $alpha = $st['alpha']; $dx = $st['dx']; $dy = $st['dy']; $scale = $st['scale'];
            $x = (int)($x + $dx);
            $y = (int)($y + $dy);
            $w = (int)max(1, $w * $scale);
            $h = (int)max(1, $h * $scale);
            $this->animStates[$aid] = $st;
        }

        // Opacity is applied to the whole element subtree via a cairo group.
        $grouped = $alpha < 0.999;
        if ($grouped) {
            Cairo::pushGroup($cr);
        }

        switch ($t) {
            case 'panel':
                Cairo::fillRect($cr, $x, $y, $w, $h, ...$this->col('surface'));
                Cairo::strokeRect($cr, $x, $y, $w, $h, ...$this->col('border'));
                Cairo::text($cr, $x + 8, $y + 18, (string) $el->prop('title', ''), 13, ...$this->col('text'));
                break;
            case 'label':
                Cairo::text($cr, $x, $y + 13, (string) $el->prop('text', ''), 13, ...$this->col('text'));
                break;
            case 'heading':
                Cairo::text($cr, $x, $y + 18, (string) $el->prop('text', ''), 18, ...$this->col('text'));
                break;
            case 'button':
                Cairo::fillRect($cr, $x, $y, $w, $h, ...$this->col('surface'));
                Cairo::strokeRect($cr, $x, $y, $w, $h, ...$this->col('border'));
                $txt = (string) $el->prop('text', '');
                $e = Cairo::textExtents($cr, $txt);
                Cairo::text($cr, $x + ($w - $e['w']) / 2, $y + $h / 2 + 4, $txt, 13, ...$this->col('text'));
                break;
            case 'input':
            case 'textarea':
                Cairo::fillRect($cr, $x, $y, $w, $h, ...$this->col('surface'));
                Cairo::strokeRect($cr, $x, $y, $w, $h, ...$this->col('border'));
                $this->drawFieldText($cr, $n, $x + 6, $y + 4, $w - 12, $h - 8, 0);
                break;
            case 'checkbox':
            case 'radio':
                Cairo::strokeRect($cr, $x, $y + 2, 14, 14, ...$this->col('border'));
                if ($el->prop('checked')) {
                    Cairo::fillRect($cr, $x + 3, $y + 5, 8, 8, ...$this->col('accent'));
                }
                Cairo::text($cr, $x + 20, $y + 13, (string) $el->prop('text', ''), 13, ...$this->col('text'));
                break;
            case 'slider':
                Cairo::strokeRect($cr, $x, $y + 8, $w, 8, ...$this->col('border'));
                $min = (int) $el->prop('min', 0);
                $max = (int) $el->prop('max', 100);
                $val = (int) $el->prop('value', 0);
                $frac = $max > $min ? ($val - $min) / ($max - $min) : 0;
                Cairo::fillRect($cr, $x + $frac * ($w - 8), $y + 4, 8, 16, ...$this->col('accent'));
                break;
            case 'progress':
                Cairo::strokeRect($cr, $x, $y, $w, $h, ...$this->col('border'));
                $val = (float) $el->prop('value', 0);
                $frac = $val > 1 ? $val / 100 : $val;
                Cairo::fillRect($cr, $x, $y, (int) ($w * $frac), $h, ...$this->col('accent'));
                break;
            case 'select':
                Cairo::fillRect($cr, $x, $y, $w, $h, ...$this->col('surface'));
                Cairo::strokeRect($cr, $x, $y, $w, $h, ...$this->col('border'));
                $opts = $el->prop('options', []);
                $sel = (int) $el->prop('value', 0);
                $txt = $opts[$sel] ?? '';
                Cairo::text($cr, $x + 6, $y + 16, (string) $txt, 13, ...$this->col('text'));
                if (!empty($this->expanded[(string) ($el->prop('id') ?? '')])) {
                    $popTop = $y + $h;
                    foreach ($opts as $i => $o) {
                        $oy = $popTop + $i * 20;
                        Cairo::fillRect($cr, $x, $oy, $w, 20, ...$this->col('surface'));
                        Cairo::strokeRect($cr, $x, $oy, $w, 20, ...$this->col('border'));
                        if ($i === $sel) {
                            Cairo::fillRect($cr, $x, $oy, $w, 20, ...$this->col('selected'));
                        }
                        Cairo::text($cr, $x + 6, $oy + 14, (string) $o, 13, ...$this->col('text'));
                    }
                }
                break;
            case 'list':
                $items = $el->prop('items', []);
                Cairo::fillRect($cr, $x, $y, $w, $h, ...$this->col('surface'));
                Cairo::strokeRect($cr, $x, $y, $w, $h, ...$this->col('border'));
                // Virtual lists hand their rows to the windowed list_item nodes
                // (correctly offset + clipped); only non-virtual item lists keep
                // the legacy all-rows render here.
                if ($items !== [] && !(bool) $el->prop('virtual', false)) {
                    $sel = (int) $el->prop('value', -1);
                    $hl = $this->highlights[(string) ($el->prop('id') ?? '')] ?? -1;
                    foreach ($items as $i => $it) {
                        $iy = $y + 4 + $i * 20;
                        if ($i === $sel) {
                            Cairo::fillRect($cr, $x, $iy, $w, 20, ...$this->col('selected'));
                        } elseif ($i === $hl) {
                            Cairo::strokeRect($cr, $x + 1, $iy + 1, $w - 2, 18, ...[...$this->col('accent'), 2.0]);
                        }
                        Cairo::text($cr, $x + 6, $iy + 14, (string) $it, 13, ...$this->col('text'));
                    }
                }
                break;
            case 'toggle':
                $this->drawToggle($cr, $el, $x, $y, $w, $h);
                break;
            case 'iconbutton':
                $this->drawIconButton($cr, $el, $x, $y, $w, $h);
                break;
            case 'segmented':
                $this->drawSegmented($cr, $el, $x, $y, $w, $h);
                break;
            case 'search':
                $this->drawSearch($cr, $el, $x, $y, $w, $h);
                break;
            case 'list_item':
                $this->drawListItem($cr, $el, $x, $y, $w, $h);
                break;
            case 'split':
                $this->drawSplit($cr, $el, $x, $y, $w, $h);
                break;
            case 'webview':
                $this->drawPlaceholder($cr, $x, $y, $w, $h, 'WebView' . ($el->prop('url') !== '' ? ': ' . (string) $el->prop('url') : ''));
                break;
            case 'gpusurface':
                $this->drawPlaceholder($cr, $x, $y, $w, $h, 'GPU surface');
                break;
            case 'custom':
                // App-supplied drawing callback: receives the cairo context, the
                // element's screen rect, and this backend, so users can paint
                // anything Cairo supports without a native widget.
                $cb = $el->prop('draw');
                if (is_callable($cb)) {
                    $cb($cr, $x, $y, $w, $h, $this);
                }
                break;
            case 'toolbar':
            case 'statusbar':
            case 'titlebar':
            case 'sidebar':
                $this->drawStrip($cr, $t, $x, $y, $w, $h);
                break;
            case 'image':
                Cairo::fillRect($cr, $x, $y, $w, $h, ...$this->col('surface'));
                Cairo::strokeRect($cr, $x, $y, $w, $h, ...$this->col('border'));
                break;
            case 'divider':
                Cairo::line($cr, $x, $y + 0.5, $x + $w, $y + 0.5, ...$this->col('border'));
                break;
            case 'window':
            case 'column':
            case 'row':
            case 'stack':
            case 'spacer':
                break;

            // ---- Phase 3 widgets ----
            case 'tabs':
            case 'accordion':
            case 'scroll':
            case 'menu':
            case 'tree':
            case 'button_group':
                Cairo::strokeRect($cr, $x, $y, $w, $h, ...$this->col('border'));
                break;
            case 'tab':
                Cairo::strokeRect($cr, $x, $y, $w, $h, ...$this->col('border'));
                Cairo::text($cr, $x + 6, $y + 18, (string) $el->prop('title', ''), 12, ...$this->col('text'));
                break;
            case 'card':
                Cairo::fillRect($cr, $x, $y, $w, $h, ...$this->col('surface'));
                Cairo::strokeRect($cr, $x, $y, $w, $h, ...$this->col('border'));
                Cairo::text($cr, $x + 8, $y + 18, (string) $el->prop('title', ''), 13, ...$this->col('text'));
                break;
            case 'alert':
                $sev = (string) $el->prop('severity', 'info');
                $accent = match ($sev) {
                    'error' => [0.8, 0.2, 0.2],
                    'warn' => [0.9, 0.6, 0.1],
                    'success' => [0.2, 0.6, 0.3],
                    default => $this->col('accent'),
                };
                Cairo::fillRect($cr, $x, $y, $w, $h, ...$this->col('surface'));
                Cairo::strokeRect($cr, $x, $y, $w, $h, ...$accent);
                Cairo::fillRect($cr, $x, $y, 4, $h, ...$accent);
                Cairo::text($cr, $x + 10, $y + 16, (string) $el->prop('text', ''), 12, ...$this->col('text'));
                break;
            case 'dialog':
            case 'sheet':
            case 'drawer':
                Cairo::fillRect($cr, $x, $y, $w, $h, ...$this->col('surface'));
                Cairo::strokeRect($cr, $x, $y, $w, $h, ...$this->col('border'));
                Cairo::text($cr, $x + 8, $y + 18, (string) $el->prop('title', ''), 13, ...$this->col('text'));
                break;
            case 'table':
                Cairo::strokeRect($cr, $x, $y, $w, $h, ...$this->col('border'));
                $cols = $el->prop('columns', []);
                $rows = $el->prop('rows', []);
                $nc = max(1, count($cols));
                $ch = count($rows) > 0 ? (int) ($h / (count($rows) + 1)) : 20;
                $cw = (int) ($w / $nc);
                foreach ($cols as $i => $c) {
                    Cairo::text($cr, $x + $i * $cw + 4, $y + 16, (string) $c, 11, ...$this->col('textMuted'));
                }
                foreach ($rows as $r => $row) {
                    foreach ($row as $i => $cell) {
                        Cairo::text($cr, $x + $i * $cw + 4, $y + 16 + ($r + 1) * $ch, (string) $cell, 11, ...$this->col('text'));
                    }
                }
                break;
            case 'combobox':
            case 'dropdown':
                Cairo::fillRect($cr, $x, $y, $w, $h, ...$this->col('surface'));
                Cairo::strokeRect($cr, $x, $y, $w, $h, ...$this->col('border'));
                $opts = $el->prop('options', []);
                $sel = (int) $el->prop('value', 0);
                Cairo::text($cr, $x + 6, $y + 16, (string) ($opts[$sel] ?? ($opts[0] ?? '')), 12, ...$this->col('text'));
                break;
            case 'menu_item':
                Cairo::text($cr, $x + 6, $y + 16, (string) $el->prop('title', ''), 12, ...$this->col('text'));
                break;
            case 'tree_node':
                Cairo::text($cr, $x + 6, $y + 16, (string) $el->prop('title', ''), 12, ...$this->col('text'));
                break;
            case 'acc_header':
                Cairo::text($cr, $x + 6, $y + 18, (string) $el->prop('title', ''), 12, ...$this->col('text'));
                break;
            case 'chart':
                Cairo::fillRect($cr, $x, $y, $w, $h, ...$this->col('surface'));
                Cairo::strokeRect($cr, $x, $y, $w, $h, ...$this->col('border'));
                $data = $el->prop('data', []);
                $n = count($data);
                if ($n > 0) {
                    $max = 1;
                    foreach ($data as $d) {
                        $v = is_array($d) ? ((float) ($d[1] ?? 0)) : (float) $d;
                        $max = max($max, $v);
                    }
                    $bw = $w / $n;
                    foreach ($data as $i => $d) {
                        $v = is_array($d) ? ((float) ($d[1] ?? 0)) : (float) $d;
                        $bh = (int) ($h * $v / $max);
                        Cairo::fillRect($cr, $x + $i * $bw + 2, $y + $h - $bh, $bw - 4, $bh, ...$this->col('accent'));
                    }
                }
                break;
            case 'tooltip':
                Cairo::fillRect($cr, $x, $y, $w, $h, ...$this->col('text'));
                Cairo::text($cr, $x + 6, $y + 15, (string) $el->prop('text', ''), 11, ...$this->col('surface'));
                break;
            case 'badge':
                Cairo::fillRect($cr, $x, $y, $w, $h, ...$this->col('accent'));
                Cairo::text($cr, $x + 6, $y + 15, (string) $el->prop('text', ''), 11, ...$this->col('surface'));
                break;
            case 'avatar':
                Cairo::fillRect($cr, $x, $y, $w, $h, ...$this->col('accentSoft'));
                Cairo::text($cr, $x + $w / 2 - 4, $y + $h / 2 + 4, strtoupper(substr((string) $el->prop('text', ''), 0, 1)), 14, ...$this->col('text'));
                break;
            case 'skeleton':
                $lines = (int) $el->prop('lines', 3);
                for ($i = 0; $i < $lines; $i++) {
                    Cairo::fillRect($cr, $x + 4, $y + 4 + $i * 14, $w - 8, 8, ...$this->col('surfaceAlt'));
                }
                break;
            case 'spinner':
                Cairo::strokeRect($cr, $x, $y, $w, $h, ...$this->col('border'));
                Cairo::text($cr, $x + $w / 2 - 4, $y + $h / 2 + 4, '…', 14, ...$this->col('text'));
                break;
            case 'switch':
                $on = (bool) $el->prop('checked');
                Cairo::fillRect($cr, $x, $y, 44, 22, ...($on ? $this->col('accent') : $this->col('border')));
                Cairo::fillRect($cr, $x + ($on ? 22 : 2), $y + 2, 18, 18, ...$this->col('surface'));
                break;
            case 'richtext':
                $ty = $y + 14;
                foreach ($el->prop('spans', []) as $s) {
                    $txt = (string) ($s['text'] ?? '');
                    Cairo::text($cr, $x + 4, $ty, $txt, !empty($s['bold']) ? 13 : 12, ...$this->col('text'));
                    $ty += 16;
                }
                break;
            case 'breadcrumb':
                $crumbs = $el->prop('crumbs', []);
                $cx = $x + 4;
                $cnt = count($crumbs);
                foreach ($crumbs as $i => $c) {
                    Cairo::text($cr, $cx, $y + 16, (string) $c, 12, ...$this->col('text'));
                    $ew = Cairo::textExtents($cr, (string) $c)['w'];
                    $cx += (int) $ew + 14;
                    if ($i < $cnt - 1) {
                        Cairo::text($cr, $cx - 10, $y + 16, '›', 12, ...$this->col('textMuted'));
                    }
                }
                break;
            case 'pagination':
                $page = (int) $el->prop('page', 1);
                $pages = (int) $el->prop('pages', 1);
                Cairo::text($cr, $x + 4, $y + 16, "‹ $page / $pages ›", 12, ...$this->col('text'));
                break;
            default:
                Cairo::strokeRect($cr, $x, $y, $w, $h, 0.8, 0.4, 0.4);
                break;
        }

        // Real-window focus feedback: an accent ring on the focused widget.
        $id = $n->el->prop('id');
        if ($id !== null && (string) $id === $this->focusId
            && in_array($n->type, self::FOCUSABLE, true)) {
            Cairo::strokeRect($cr, $x - 1.5, $y - 1.5, $w + 3, $h + 3, 0.2, 0.4, 0.9, 2.0);
        }

        if ($grouped) {
            Cairo::popGroupToSource($cr);
            Cairo::paintWithAlpha($cr, max(0.0, min(1.0, $alpha)));
        }
    }

    private function drawToggle($cr, Element $el, float $x, float $y, float $w, float $h): void
    {
        $on = (bool) $el->prop('on');
        $label = (string) $el->prop('text', '');
        $sw = 44;
        $swx = $x + $w - $sw;
        $sy = $y + ($h - 22) / 2;
        Cairo::fillRect($cr, $swx, $sy, $sw, 22, ...($on ? $this->col('accent') : $this->col('border')));
        $kx = $on ? $swx + $sw - 22 : $swx + 2;
        Cairo::fillRect($cr, $kx + 2, $sy + 2, 18, 18, ...$this->col('surface'));
        if ($label !== '') {
            Cairo::text($cr, $x + 2, $y + $h / 2 + 4, $label, 13, ...$this->col('text'));
        }
    }

    private function drawIconButton($cr, Element $el, float $x, float $y, float $w, float $h): void
    {
        Cairo::fillRect($cr, $x, $y, $w, $h, ...$this->col('surface'));
        Cairo::strokeRect($cr, $x, $y, $w, $h, ...$this->col('border'));
        $icon = (string) $el->prop('icon', '');
        $txt = (string) $el->prop('text', '');
        if ($icon !== '') {
            Cairo::text($cr, $x + 8, $y + $h / 2 + 4, $icon, 13, ...$this->col('text'));
            if ($txt !== '') {
                Cairo::text($cr, $x + 28, $y + $h / 2 + 4, $txt, 13, ...$this->col('text'));
            }
            return;
        }
        $e = Cairo::textExtents($cr, $txt);
        Cairo::text($cr, $x + ($w - $e['w']) / 2, $y + $h / 2 + 4, $txt, 13, ...$this->col('text'));
    }

    private function drawSegmented($cr, Element $el, float $x, float $y, float $w, float $h): void
    {
        $options = $el->prop('options', []);
        $sel = (int) $el->prop('value', 0);
        $n = count($options) ?: 1;
        $seg = $w / $n;
        Cairo::fillRect($cr, $x, $y, $w, $h, ...$this->col('surface'));
        Cairo::strokeRect($cr, $x, $y, $w, $h, ...$this->col('border'));
        for ($i = 0; $i < $n; $i++) {
            if ($i === $sel) {
                Cairo::fillRect($cr, $x + $i * $seg + 2, $y + 2, $seg - 4, $h - 4, ...$this->col('surfaceAlt'));
            }
            $txt = (string) ($options[$i] ?? '');
            $e = Cairo::textExtents($cr, $txt);
            Cairo::text($cr, $x + $i * $seg + ($seg - $e['w']) / 2, $y + $h / 2 + 4, $txt, 13, ...$this->col('text'));
        }
    }

    private function drawSearch($cr, Element $el, float $x, float $y, float $w, float $h): void
    {
        Cairo::fillRect($cr, $x, $y, $w, $h, ...$this->col('surface'));
        Cairo::strokeRect($cr, $x, $y, $w, $h, ...$this->col('border'));
        Cairo::text($cr, $x + 8, $y + $h / 2 + 4, '🔍', 13, ...$this->col('textMuted'));
        $id = (string) $el->prop('id', '');
        $buf = $this->fields[$id] ?? null;
        $text = $buf !== null ? $buf['text'] : (string) $el->prop('text', '');
        $showPlaceholder = $text === '' && $id !== $this->focusId;
        $tc = $this->col($showPlaceholder ? 'textMuted' : 'text');
        $tx = $x + 28;
        $ty = $y + $h / 2 + 4;
        Cairo::text($cr, $tx, $ty, $showPlaceholder ? (string) $el->prop('placeholder', '') : $text, 13, ...$tc);
        if ($buf !== null) {
            if ($buf['sel'] !== $buf['cursor']) {
                $this->drawFieldSelection($cr, $text, $buf['sel'], $buf['cursor'], $tx, $ty - 12, 0);
            }
            $this->drawComposition($cr, $el, $text, $tx, $ty, $w - 34);
            if ($this->caretVisible()) {
                [$cx, $cy] = $this->fieldCaretXY($cr, $text, $buf['cursor'], $tx, $ty - 12, 0);
                Cairo::fillRect($cr, $cx, $cy, 1.2, 16, ...$this->col('text'));
            }
        }
    }

    private function drawListItem($cr, Element $el, float $x, float $y, float $w, float $h): void
    {
        Cairo::fillRect($cr, $x, $y, $w, $h, ...$this->col('surface'));
        $sel = (bool) $el->prop('_sel', false);
        $listId = $el->prop('_listId');
        $idx = (int) $el->prop('_index', -1);
        $hl = ($listId !== null && $idx >= 0 && ($this->highlights[(string) $listId] ?? -1) === $idx);
        if ($sel) {
            Cairo::fillRect($cr, $x, $y, $w, $h, ...$this->col('selected'));
        } elseif ($hl) {
            Cairo::strokeRect($cr, $x + 1, $y + 1, $w - 2, $h - 2, ...[...$this->col('accent'), 2.0]);
        }
        $icon = (string) $el->prop('icon', '');
        if ($icon !== '') {
            Cairo::text($cr, $x + 10, $y + $h / 2 + 5, $icon, 14, ...$this->col('textMuted'));
        }
        $title = (string) $el->prop('title', '');
        $sub = (string) $el->prop('subtitle', '');
        if ($sub !== '') {
            Cairo::text($cr, $x + 36, $y + 16, $title, 13, ...$this->col('text'));
            Cairo::text($cr, $x + 36, $y + 34, $sub, 12, ...$this->col('textMuted'));
        } else {
            Cairo::text($cr, $x + 36, $y + $h / 2 + 4, $title, 13, ...$this->col('text'));
        }
    }

    private function drawSplit($cr, Element $el, float $x, float $y, float $w, float $h): void
    {
        $vertical = $el->prop('orientation') === 'vertical';
        $pos = (float) $el->prop('position', 0.5);
        Cairo::fillRect($cr, $x, $y, $w, $h, ...$this->col('bg'));
        if ($vertical) {
            $dy = (int) ($y + $pos * $h);
            Cairo::fillRect($cr, $x, $dy - 3, $w, 6, ...$this->col('surfaceAlt'));
        } else {
            $dx = (int) ($x + $pos * $w);
            Cairo::fillRect($cr, $dx - 3, $y, 6, $h, ...$this->col('surfaceAlt'));
        }
    }

    private function drawStrip($cr, string $type, float $x, float $y, float $w, float $h): void
    {
        $bg = match ($type) {
            'sidebar' => $this->col('surfaceAlt'),
            'statusbar' => $this->col('bg'),
            default => $this->col('surfaceAlt'),
        };
        Cairo::fillRect($cr, $x, $y, $w, $h, ...$bg);
    }

    private function drawPlaceholder($cr, float $x, float $y, float $w, float $h, string $label): void
    {
        Cairo::fillRect($cr, $x, $y, $w, $h, ...$this->col('surface'));
        Cairo::strokeRect($cr, $x, $y, $w, $h, ...$this->col('border'));
        Cairo::text($cr, $x + 10, $y + 22, $label, 12, ...$this->col('textMuted'));
    }

    /** Pointer drag: MOVE updates a slider's value; UP ends the drag. */
    private function onPointerDrag(int $kind, float $x, float $y): void
    {
        if ($this->drag === null) {
            return;
        }
        $d = $this->drag;
        if ($kind === self::POINTER_UP) {
            $this->drag = null;
            return;
        }
        if ($d['type'] === 'split') {
            $node = $this->findNodeById($d['id']);
            if ($node !== null) {
                $vertical = $node->el->prop('orientation') === 'vertical';
                $frac = $vertical
                    ? ($y - $node->y) / max(1, $node->h)
                    : ($x - $node->x) / max(1, $node->w);
                $frac = max(0.05, min(0.95, $frac));
                $msg = $node->el->prop('onChange');
                if (is_string($msg) && $msg !== '') {
                    ($this->dispatch)($msg, (int) ($frac * 100));
                }
            }
            return;
        }
        if ($d['type'] === 'scrollbar') {
            $node = $this->findNodeById($d['id']);
            if ($node !== null) {
                $raw = $y - $d['grab'] - $d['trackY'];
                $off = (int) (($raw * $d['maxOff']) / max(1, $d['trackH'] - $d['thumbH']));
                $this->scrollTo($d['id'], $off);
            }
            return;
        }
        if ($d['type'] !== 'slider') {
            return;
        }
        $node = $this->findNodeById($d['id']);
        if ($node === null) {
            $this->drag = null;
            return;
        }
        $val = $this->sliderValueAt($node, $x);
        $msg = $node->el->prop('onChange');
        if (is_string($msg) && $msg !== '') {
            ($this->dispatch)($msg, $val);
        }
    }

    /** Slider value for a pointer x, clamped to [min, max]. */
    private function sliderValueAt(Node $node, float $x): int
    {
        $min = (int) $node->el->prop('min', 0);
        $max = (int) $node->el->prop('max', 100);
        $frac = $node->w > 0 ? ($x - $node->x) / $node->w : 0;
        $frac = max(0.0, min(1.0, $frac));
        return (int) round($min + $frac * ($max - $min));
    }

    /** Pick an option from an expanded dropdown popup. */
    private function pickSelectOption(Node $node, string $id, float $y): void
    {
        $opts = $node->el->prop('options', []);
        $idx = (int) (($y - ($node->y + $node->h)) / 20);
        if ($idx >= 0 && $idx < count($opts)) {
            $this->expanded[$id] = false;
            $msg = $node->el->prop('onChange');
            if (is_string($msg) && $msg !== '') {
                ($this->dispatch)($msg, $idx);
            }
            if ($this->host) {
                Phpc::call($this->ffi, 'ui3_host_request_redraw', [$this->host]);
            }
        }
    }

    private function findNodeById(string $id): ?Node
    {
        foreach ($this->layout as $n) {
            if (($n->el->prop('id') ?? null) === $id) {
                return $n;
            }
        }
        return null;
    }

    public function isExpanded(string $id): bool
    {
        return !empty($this->expanded[$id]);
    }

    /** Inject a pointer move (headless drag driving). */
    public function injectPointerMove(float $x, float $y): void
    {
        Phpc::call($this->ffi, 'ui3_host_inject_move', [$this->host, $x, $y]);
    }

    /** Whether the host fell back to offscreen (no display available). */
    public function isHeadless(): bool
    {
        if ($this->host === null) {
            return $this->headless;
        }
        return (int) Phpc::call($this->ffi, 'ui3_host_is_headless', [$this->host]) === 1;
    }

    /**
     * Render the current tree to an offscreen ARGB32 surface and return its
     * pixels as [y][x] => [r,g,b]. Headless snapshot readback (mirrors the
     * Reference backend's pixelsHash) used to verify painting — e.g. that a
     * scroll container clips its overflowing content.
     */
    public function offscreenPixels(): array
    {
        $f = Cairo::ffi();
        $w = $this->root ? (int) $this->root->prop('width', 320) : 320;
        $h = $this->root ? (int) $this->root->prop('height', 240) : 240;
        $surf = $f->cairo_image_surface_create(0, $w, $h); // CAIRO_FORMAT_ARGB32
        $cr = $f->cairo_create($surf);
        $this->paint($cr);
        $f->cairo_destroy($cr);
        $f->cairo_surface_flush($surf);
        $data = $f->cairo_image_surface_get_data($surf);
        $stride = $f->cairo_image_surface_get_stride($surf);
        $px = [];
        for ($y = 0; $y < $h; $y++) {
            $row = [];
            for ($x = 0; $x < $w; $x++) {
                $o = $y * $stride + $x * 4;
                // ARGB32 is stored BGRA in memory on little-endian hosts.
                $row[] = [$data[$o + 2], $data[$o + 1], $data[$o]];
            }
            $px[] = $row;
        }
        $f->cairo_surface_destroy($surf);
        return ['w' => $w, 'h' => $h, 'px' => $px];
    }

    /** Number of frames actually painted (proves the real-window draw path). */
    public function framesDrawn(): int
    {
        return $this->framesDrawn;
    }
}
