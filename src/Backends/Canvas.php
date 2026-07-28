<?php
declare(strict_types=1);

namespace Yangweijie\Ui3\Backends;

use Kingbes\Phpc\Phpc;
use Yangweijie\Ui3\Backend;
use Yangweijie\Ui3\Canvas\Layout;
use Yangweijie\Ui3\Canvas\Node;
use Yangweijie\Ui3\Element;
use Yangweijie\Ui3\FFI\{Cairo, LibUi3};

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

    private const POINTER_DOWN = 1;
    private const POINTER_UP = 2;
    private const POINTER_MOVE = 3;
    private const KEY = 4;
    private const KEY_ESC = "\x1b";
    private const KEY_LEFT = "\x01";
    private const KEY_RIGHT = "\x02";
    private const KEY_UP = "\x03";
    private const KEY_DOWN = "\x04";
    /** Widget types that join the Tab order and show a focus ring. */
    private const FOCUSABLE = ['input', 'textarea', 'button', 'iconbutton', 'checkbox', 'radio', 'select', 'list', 'toggle', 'segmented', 'search'];
    /** Widget types browsable with arrow keys + Enter. */
    private const NAVIGABLE = ['list', 'select', 'segmented'];

    public function __construct(private bool $headless = false)
    {
        $this->ffi = LibUi3::ffi();
    }

    public function mount(Element $root, \Closure $dispatch): void
    {
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
    public function injectPointer(float $x, float $y, bool $down): void
    {
        Phpc::call($this->ffi, 'ui3_host_inject_pointer', [$this->host, $x, $y, $down ? 1 : 0]);
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
        $this->fields[$id] = ['text' => '', 'cursor' => 0];
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

    /** Visible text for a field node: edit buffer > element value > placeholder. */
    private function displayTextFor(Node $node): string
    {
        $id = (string) $node->el->prop('id', '');
        $buf = ($id !== '' && isset($this->fields[$id]))
            ? $this->fields[$id]['text']
            : (string) $node->el->prop('text', '');
        return $buf !== '' ? $buf : (string) $node->el->prop('placeholder', '');
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
        $this->framesDrawn++;
        $w = $this->root ? (int) $this->root->prop('width', 320) : 320;
        $h = $this->root ? (int) $this->root->prop('height', 240) : 240;
        Cairo::fillRect($cr, 0, 0, $w, $h, 1, 1, 1);
        if (!$this->root) {
            return;
        }
        $this->layout = Layout::compute($this->root);
        foreach ($this->layout as $n) {
            $this->drawNode($cr, $n);
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
        // Drag (MOVE/UP) is driven by the in-progress drag, not by hit-testing.
        if ($kind === self::POINTER_MOVE || $kind === self::POINTER_UP) {
            $this->onPointerDrag($kind, $x, $y);
            return;
        }
        if ($kind !== self::POINTER_DOWN) {
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
        $node = Layout::hitTest($this->layout, $x, $y);
        if (!$node || !$this->dispatch) {
            return;
        }
        // Clicking a focusable widget focuses it, like a real window.
        if (in_array($node->type, self::FOCUSABLE, true)
            && ($id = $node->el->prop('id')) !== null) {
            $this->applyFocus((string) $id);
        }
        $this->fire($node, $x, $y);
    }

    /** Route a keystroke: Tab, list/select browsing, or text editing. */
    private function onKey(?string $text): void
    {
        if ($text === null || $this->dispatch === null) {
            return;
        }
        // Escape closes any expanded dropdown.
        if ($text === self::KEY_ESC) {
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
        $id = (string) $node->el->prop('id');
        $this->seedField($id);
        $buf = &$this->fields[$id];
        $t = $buf['text'];
        $c = $buf['cursor'];

        if ($text === self::KEY_LEFT) {
            $buf['cursor'] = $c > 0 ? $c - 1 : 0;
            return;
        }
        if ($text === self::KEY_RIGHT) {
            $buf['cursor'] = $c < mb_strlen($t) ? $c + 1 : $c;
            return;
        }
        // Arrow Up/Down only browse list/select; a text field ignores them.
        if ($text === self::KEY_UP || $text === self::KEY_DOWN) {
            return;
        }
        if ($text === "\x08") {          // backspace: delete before cursor
            if ($c <= 0) {
                return;
            }
            $t = mb_substr($t, 0, $c - 1) . mb_substr($t, $c);
            $c--;
        } elseif (mb_strlen($text) === 1) { // a printable keystroke
            $t = mb_substr($t, 0, $c) . $text . mb_substr($t, $c);
            $c++;
        } else {
            return; // not a single keystroke
        }
        $buf['text'] = $t;
        $buf['cursor'] = $c;

        // Emit the new full value — exactly what a real text control does.
        $h = $node->el->prop('onInput');
        if (is_string($h) && $h !== '') {
            ($this->dispatch)($h, $t);
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
        $this->fields[$id] = ['text' => $text, 'cursor' => mb_strlen($text)];
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

    private function drawNode($cr, Node $n): void
    {
        $el = $n->el;
        $t = $n->type;
        $x = $n->x; $y = $n->y; $w = $n->w; $h = $n->h;
        $f = Cairo::ffi();

        switch ($t) {
            case 'panel':
                Cairo::fillRect($cr, $x, $y, $w, $h, 0.96, 0.96, 0.98);
                Cairo::strokeRect($cr, $x, $y, $w, $h, 0.7, 0.7, 0.75);
                Cairo::text($cr, $x + 8, $y + 18, (string) $el->prop('title', ''), 13, 0.2, 0.2, 0.2);
                break;
            case 'label':
                Cairo::text($cr, $x, $y + 13, (string) $el->prop('text', ''), 13, 0.1, 0.1, 0.1);
                break;
            case 'heading':
                Cairo::text($cr, $x, $y + 18, (string) $el->prop('text', ''), 18, 0, 0, 0);
                break;
            case 'button':
                Cairo::fillRect($cr, $x, $y, $w, $h, 0.93, 0.93, 0.95);
                Cairo::strokeRect($cr, $x, $y, $w, $h, 0.6, 0.6, 0.65);
                $txt = (string) $el->prop('text', '');
                $e = Cairo::textExtents($cr, $txt);
                Cairo::text($cr, $x + ($w - $e['w']) / 2, $y + $h / 2 + 4, $txt, 13, 0.1, 0.1, 0.1);
                break;
            case 'input':
            case 'textarea':
                Cairo::fillRect($cr, $x, $y, $w, $h, 1, 1, 1);
                Cairo::strokeRect($cr, $x, $y, $w, $h, 0.7, 0.7, 0.75);
                $show = $this->displayTextFor($n);
                $c = $show !== '' && $show !== (string) $el->prop('placeholder', '') ? 0.1 : 0.5;
                Cairo::text($cr, $x + 6, $y + 16, $show, 13, $c, $c, $c);
                break;
            case 'checkbox':
            case 'radio':
                Cairo::strokeRect($cr, $x, $y + 2, 14, 14, 0.5, 0.5, 0.55);
                if ($el->prop('checked')) {
                    Cairo::fillRect($cr, $x + 3, $y + 5, 8, 8, 0.2, 0.4, 0.9);
                }
                Cairo::text($cr, $x + 20, $y + 13, (string) $el->prop('text', ''), 13, 0.1, 0.1, 0.1);
                break;
            case 'slider':
                Cairo::strokeRect($cr, $x, $y + 8, $w, 8, 0.8, 0.8, 0.82);
                $min = (int) $el->prop('min', 0);
                $max = (int) $el->prop('max', 100);
                $val = (int) $el->prop('value', 0);
                $frac = $max > $min ? ($val - $min) / ($max - $min) : 0;
                Cairo::fillRect($cr, $x + $frac * ($w - 8), $y + 4, 8, 16, 0.2, 0.4, 0.9);
                break;
            case 'progress':
                Cairo::strokeRect($cr, $x, $y, $w, $h, 0.8, 0.8, 0.82);
                $val = (float) $el->prop('value', 0);
                $frac = $val > 1 ? $val / 100 : $val;
                Cairo::fillRect($cr, $x, $y, (int) ($w * $frac), $h, 0.2, 0.6, 0.3);
                break;
            case 'select':
                Cairo::fillRect($cr, $x, $y, $w, $h, 1, 1, 1);
                Cairo::strokeRect($cr, $x, $y, $w, $h, 0.7, 0.7, 0.75);
                $opts = $el->prop('options', []);
                $sel = (int) $el->prop('value', 0);
                $txt = $opts[$sel] ?? '';
                Cairo::text($cr, $x + 6, $y + 16, (string) $txt, 13, 0.1, 0.1, 0.1);
                if (!empty($this->expanded[(string) ($el->prop('id') ?? '')])) {
                    $popTop = $y + $h;
                    foreach ($opts as $i => $o) {
                        $oy = $popTop + $i * 20;
                        Cairo::fillRect($cr, $x, $oy, $w, 20, 1, 1, 1);
                        Cairo::strokeRect($cr, $x, $oy, $w, 20, 0.7, 0.7, 0.75);
                        if ($i === $sel) {
                            Cairo::fillRect($cr, $x, $oy, $w, 20, 0.85, 0.9, 0.98);
                        }
                        Cairo::text($cr, $x + 6, $oy + 14, (string) $o, 13, 0.1, 0.1, 0.1);
                    }
                }
                break;
            case 'list':
                $items = $el->prop('items', []);
                if ($items === []) {
                    break; // rows (list_item) are drawn by their own nodes
                }
                Cairo::fillRect($cr, $x, $y, $w, $h, 1, 1, 1);
                Cairo::strokeRect($cr, $x, $y, $w, $h, 0.7, 0.7, 0.75);
                $sel = (int) $el->prop('value', -1);
                $hl = $this->highlights[(string) ($el->prop('id') ?? '')] ?? -1;
                foreach ($items as $i => $it) {
                    $iy = $y + 4 + $i * 20;
                    if ($i === $sel) {
                        Cairo::fillRect($cr, $x, $iy, $w, 20, 0.85, 0.9, 0.98);
                    } elseif ($i === $hl) {
                        Cairo::strokeRect($cr, $x + 1, $iy + 1, $w - 2, 18, 0.2, 0.4, 0.9, 2.0);
                    }
                    Cairo::text($cr, $x + 6, $iy + 14, (string) $it, 13, 0.1, 0.1, 0.1);
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
            case 'toolbar':
            case 'statusbar':
            case 'titlebar':
            case 'sidebar':
                $this->drawStrip($cr, $t, $x, $y, $w, $h);
                break;
            case 'image':
                Cairo::fillRect($cr, $x, $y, $w, $h, 0.9, 0.9, 0.92);
                Cairo::strokeRect($cr, $x, $y, $w, $h, 0.7, 0.7, 0.75);
                break;
            case 'divider':
                Cairo::line($cr, $x, $y + 0.5, $x + $w, $y + 0.5, 0.7, 0.7, 0.75);
                break;
            case 'window':
            case 'column':
            case 'row':
            case 'stack':
            case 'spacer':
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
    }

    private function drawToggle($cr, Element $el, float $x, float $y, float $w, float $h): void
    {
        $on = (bool) $el->prop('on');
        $label = (string) $el->prop('text', '');
        $sw = 44;
        $swx = $x + $w - $sw;
        $sy = $y + ($h - 22) / 2;
        Cairo::fillRect($cr, $swx, $sy, $sw, 22, $on ? 0.2 : 0.87, $on ? 0.78 : 0.76, $on ? 0.35 : 0.8);
        $kx = $on ? $swx + $sw - 22 : $swx + 2;
        Cairo::fillRect($cr, $kx + 2, $sy + 2, 18, 18, 1, 1, 1);
        if ($label !== '') {
            Cairo::text($cr, $x + 2, $y + $h / 2 + 4, $label, 13, 0.1, 0.1, 0.1);
        }
    }

    private function drawIconButton($cr, Element $el, float $x, float $y, float $w, float $h): void
    {
        Cairo::fillRect($cr, $x, $y, $w, $h, 0.93, 0.93, 0.95);
        Cairo::strokeRect($cr, $x, $y, $w, $h, 0.6, 0.6, 0.65);
        $icon = (string) $el->prop('icon', '');
        $txt = (string) $el->prop('text', '');
        if ($icon !== '') {
            Cairo::text($cr, $x + 8, $y + $h / 2 + 4, $icon, 13, 0.1, 0.1, 0.1);
            if ($txt !== '') {
                Cairo::text($cr, $x + 28, $y + $h / 2 + 4, $txt, 13, 0.1, 0.1, 0.1);
            }
            return;
        }
        $e = Cairo::textExtents($cr, $txt);
        Cairo::text($cr, $x + ($w - $e['w']) / 2, $y + $h / 2 + 4, $txt, 13, 0.1, 0.1, 0.1);
    }

    private function drawSegmented($cr, Element $el, float $x, float $y, float $w, float $h): void
    {
        $options = $el->prop('options', []);
        $sel = (int) $el->prop('value', 0);
        $n = count($options) ?: 1;
        $seg = $w / $n;
        Cairo::fillRect($cr, $x, $y, $w, $h, 0.89, 0.9, 0.92);
        Cairo::strokeRect($cr, $x, $y, $w, $h, 0.7, 0.7, 0.75);
        for ($i = 0; $i < $n; $i++) {
            if ($i === $sel) {
                Cairo::fillRect($cr, $x + $i * $seg + 2, $y + 2, $seg - 4, $h - 4, 1, 1, 1);
            }
            $txt = (string) ($options[$i] ?? '');
            $e = Cairo::textExtents($cr, $txt);
            Cairo::text($cr, $x + $i * $seg + ($seg - $e['w']) / 2, $y + $h / 2 + 4, $txt, 13, 0.1, 0.1, 0.1);
        }
    }

    private function drawSearch($cr, Element $el, float $x, float $y, float $w, float $h): void
    {
        Cairo::fillRect($cr, $x, $y, $w, $h, 1, 1, 1);
        Cairo::strokeRect($cr, $x, $y, $w, $h, 0.7, 0.7, 0.75);
        Cairo::text($cr, $x + 8, $y + $h / 2 + 4, '🔍', 13, 0.5, 0.5, 0.5);
        $txt = (string) $el->prop('text', '');
        $showPlaceholder = $txt === '' && ((string) ($el->prop('id') ?? '') !== $this->focusId);
        Cairo::text($cr, $x + 28, $y + $h / 2 + 4, $showPlaceholder ? (string) $el->prop('placeholder', '') : $txt, 13, $showPlaceholder ? 0.5 : 0.1, $showPlaceholder ? 0.5 : 0.1, $showPlaceholder ? 0.5 : 0.1);
    }

    private function drawListItem($cr, Element $el, float $x, float $y, float $w, float $h): void
    {
        Cairo::fillRect($cr, $x, $y, $w, $h, 1, 1, 1);
        $icon = (string) $el->prop('icon', '');
        if ($icon !== '') {
            Cairo::text($cr, $x + 10, $y + $h / 2 + 5, $icon, 14, 0.4, 0.45, 0.5);
        }
        $title = (string) $el->prop('title', '');
        $sub = (string) $el->prop('subtitle', '');
        if ($sub !== '') {
            Cairo::text($cr, $x + 36, $y + 16, $title, 13, 0.1, 0.1, 0.1);
            Cairo::text($cr, $x + 36, $y + 34, $sub, 12, 0.45, 0.45, 0.5);
        } else {
            Cairo::text($cr, $x + 36, $y + $h / 2 + 4, $title, 13, 0.1, 0.1, 0.1);
        }
    }

    private function drawSplit($cr, Element $el, float $x, float $y, float $w, float $h): void
    {
        $vertical = $el->prop('orientation') === 'vertical';
        $pos = (float) $el->prop('position', 0.5);
        Cairo::fillRect($cr, $x, $y, $w, $h, 0.98, 0.98, 0.99);
        if ($vertical) {
            $dy = (int) ($y + $pos * $h);
            Cairo::fillRect($cr, $x, $dy - 3, $w, 6, 0.83, 0.85, 0.88);
        } else {
            $dx = (int) ($x + $pos * $w);
            Cairo::fillRect($cr, $dx - 3, $y, 6, $h, 0.83, 0.85, 0.88);
        }
    }

    private function drawStrip($cr, string $type, float $x, float $y, float $w, float $h): void
    {
        $bg = match ($type) {
            'sidebar' => [0.95, 0.96, 0.97],
            'statusbar' => [0.98, 0.98, 0.99],
            default => [0.92, 0.93, 0.95],
        };
        Cairo::fillRect($cr, $x, $y, $w, $h, $bg[0], $bg[1], $bg[2]);
    }

    private function drawPlaceholder($cr, float $x, float $y, float $w, float $h, string $label): void
    {
        Cairo::fillRect($cr, $x, $y, $w, $h, 0.93, 0.94, 0.95);
        Cairo::strokeRect($cr, $x, $y, $w, $h, 0.8, 0.8, 0.82);
        Cairo::text($cr, $x + 10, $y + 22, $label, 12, 0.45, 0.45, 0.5);
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

    /** Number of frames actually painted (proves the real-window draw path). */
    public function framesDrawn(): int
    {
        return $this->framesDrawn;
    }
}
