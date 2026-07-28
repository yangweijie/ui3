<?php
declare(strict_types=1);

namespace Yangweijie\Ui3\Automation;

use Yangweijie\Ui3\{App, Element};
use Yangweijie\Ui3\Backends\Canvas;

/**
 * Automation server (mirrors native's automation host): wraps an App and the
 * headless Canvas backend and exposes snapshot / control / record-replay over
 * the live UI tree. Controls are addressed by widget identity (id or text),
 * never by screen coordinates, so tests stay robust to layout changes. The
 * snapshot carries the Canvas backend's real laid-out geometry.
 */
final class Automation
{
    public function __construct(
        private App $app,
        private Canvas $backend,
    ) {
    }

    /** The rendering backend (e.g. to read real-window state like isExpanded). */
    public function backend(): Canvas
    {
        return $this->backend;
    }

    /** Start the app and attach the headless backend so dispatch stays live. */
    public function start(): self
    {
        $this->app->run($this->backend);
        return $this;
    }

    /** Current model (state lives only in the model; this is the source of truth). */
    public function model(): mixed
    {
        return $this->app->model();
    }

    /** Capture an identity-addressable snapshot with real Canvas layout bounds. */
    public function snapshot(): array
    {
        $root = $this->backend->root();
        if (!$root) {
            throw new \RuntimeException('automation not started: call start() first');
        }
        $focusId = method_exists($this->backend, 'focusedId') ? $this->backend->focusedId() : null;
        return Snapshot::capture(
            $root,
            (int) $root->prop('width', 320),
            (int) $root->prop('height', 240),
            $this->backend->layout(),
            $focusId,
        );
    }

    /**
     * Click the widget identified by its declared id.
     *
     * The widget is found by identity (not coordinates), but the actual
     * activation goes through a real pointer event at its laid-out position —
     * the very same path a real window uses — so hit-testing and the event
     * loop are exercised rather than bypassed.
     */
    public function clickById(string $id): void
    {
        $w = Snapshot::findById($this->snapshot(), $id);
        if (!$w) {
            throw new \RuntimeException("no widget with id {$id}");
        }
        $this->clickWidgetCentre($w);
    }

    /**
     * Click the first widget whose text contains $text, activated via a real
     * pointer event at its laid-out position (see clickById).
     */
    public function clickText(string $text): void
    {
        $w = Snapshot::findByText($this->snapshot(), $text);
        if (!$w) {
            throw new \RuntimeException("no widget with text containing {$text}");
        }
        $this->clickWidgetCentre($w);
    }

    /**
     * Dispatch a real pointer click at a point. Mirrors a native
     * mouse-down/up on the window: the host forwards it to the event callback,
     * which hit-tests and dispatches — so this is exactly how a real window
     * click reaches the model.
     */
    public function clickAt(float $x, float $y): void
    {
        $this->backend->injectPointer($x, $y, true);
        $this->backend->injectPointer($x, $y, false);
        $this->backend->step();
    }

    /** Activate a clickable widget by clicking its laid-out centre. */
    private function clickWidgetCentre(array $w): void
    {
        $clickable = ['button', 'checkbox', 'radio', 'slider', 'select', 'list', 'toggle', 'iconbutton', 'segmented', 'list_item'];
        if (!in_array($w['role'], $clickable, true)) {
            throw new \RuntimeException("widget {$w['id']} (role {$w['role']}) is not clickable");
        }
        $this->clickAt($w['x'] + $w['w'] / 2, $w['y'] + $w['h'] / 2);
    }

    /** Dispatch a raw message (optionally with a payload) into the model. */
    public function dispatch(string $msg, mixed $payload = null): void
    {
        $this->app->dispatch($msg, $payload);
    }

    /**
     * Type text into an input/textarea widget (by id).
     *
     * Goes through the real keyboard path: focus the field, then inject a
     * key event that onEvent routes to the focused field's onInput handler —
     * the same path a real window keystroke takes, so focus is exercised
     * rather than a coordinate-blind direct dispatch.
     */
    public function input(string $id, string $value): void
    {
        $this->focus($id);
        $this->backend->resetField($id); // replace existing content
        $this->type($value);
    }

    /**
     * Set a text field's value directly through the app's onInput message — NOT
     * through synthesized key events. Deterministic for automation and AI driving:
     * commits the whole string via the same update path a keystroke reaches, with
     * no dependency on the OS key path (Cocoa keyDown delivery, etc.).
     *
     * @throws \RuntimeException when the id is not a text field.
     */
    public function setValue(string $id, string $text): void
    {
        $w = Snapshot::findById($this->snapshot(), $id);
        if (!$w || !in_array($w['role'], ['input', 'textarea', 'search'], true)) {
            throw new \RuntimeException("no text field with id {$id}");
        }
        $this->backend->setFieldText($id, $text);
    }

    /**
     * Focus a widget by id (real-window focus). Any focusable widget may be
     * focused — inputs for typing, list/select for arrow-key browsing, etc.
     */
    public function focus(string $id): void
    {
        $w = Snapshot::findById($this->snapshot(), $id);
        if (!$w || !in_array($w['role'], ['input', 'textarea', 'list', 'select', 'button', 'checkbox', 'radio', 'toggle', 'segmented', 'search', 'iconbutton'], true)) {
            throw new \RuntimeException("no focusable widget with id {$id}");
        }
        $this->backend->focus($id);
    }

    /**
     * Type text into the focused field, one real key event per character.
     * Each keystroke is injected through the real KEY path; the backend's edit
     * buffer inserts it at the cursor, so the model receives incremental edits
     * exactly as a real window would — not one whole-string overwrite.
     */
    public function type(string $text): void
    {
        foreach (mb_str_split($text) as $ch) {
            $this->backend->postKey(0, false, $ch);
            $this->backend->step();
        }
    }

    /** Press a named key (e.g. 'Tab') through the real KEY event path. */
    public function pressKey(string $key): void
    {
        $this->backend->postKey(0, false, $key);
        $this->backend->step();
    }

    /** Move focus to the next focusable widget — real Tab-key behavior. */
    public function tab(): void
    {
        $this->pressKey('Tab');
    }

    /** Move focus to the previous focusable widget — real Shift+Tab behavior. */
    public function shiftTab(): void
    {
        $this->pressKey('Shift+Tab');
    }

    /** Press Arrow Up (browse a focused list/select). */
    public function arrowUp(): void
    {
        $this->backend->postKey(0, false, "\x03");
        $this->backend->step();
    }

    /** Press Arrow Down (browse a focused list/select). */
    public function arrowDown(): void
    {
        $this->backend->postKey(0, false, "\x04");
        $this->backend->step();
    }

    /** Press Enter (commit a list/select selection). */
    public function enter(): void
    {
        $this->backend->postKey(36, false, "\n");
        $this->backend->step();
    }

    /**
     * Feed a key by raw scancode + modifiers — drives the SAME canonical
     * translation the real Cocoa keyDown uses (#5c), so it routes through the
     * exact focus/Tab/text/browse logic a physical window would.
     */
    public function rawKey(int $keycode, bool $shift = false, string $chars = ''): void
    {
        $this->backend->postKey($keycode, $shift, $chars);
        $this->step();
    }

    /** Drag a slider (by id) to $value through real pointer down/move/up. */
    public function dragSlider(string $id, int $value): void
    {
        $w = Snapshot::findById($this->snapshot(), $id);
        if (!$w || $w['role'] !== 'slider') {
            throw new \RuntimeException("no slider with id {$id}");
        }
        $min = (int) ($w['state']['min'] ?? 0);
        $max = (int) ($w['state']['max'] ?? 100);
        $frac = $max > $min ? ($value - $min) / ($max - $min) : 0;
        $frac = max(0.0, min(1.0, $frac));
        $x = $w['x'] + $frac * $w['w'];
        $y = $w['y'] + $w['h'] / 2;
        $this->backend->injectPointer($x, $y, true);
        $this->backend->injectPointerMove($x, $y);
        $this->backend->injectPointer($x, $y, false);
        $this->step();
    }

    /** Open/close a dropdown (by id) via a real click on its box. */
    public function toggleSelect(string $id): void
    {
        $w = Snapshot::findById($this->snapshot(), $id);
        if (!$w || $w['role'] !== 'select') {
            throw new \RuntimeException("no select with id {$id}");
        }
        $this->clickAt($w['x'] + $w['w'] / 2, $w['y'] + $w['h'] / 2);
    }

    /** Pick an option in an expanded dropdown (by id + index) via real pointer. */
    public function clickSelectOption(string $id, int $index): void
    {
        $w = Snapshot::findById($this->snapshot(), $id);
        if (!$w || $w['role'] !== 'select') {
            throw new \RuntimeException("no select with id {$id}");
        }
        if (!$this->backend->isExpanded($id)) {
            $this->toggleSelect($id);
        }
        $rowY = $w['y'] + $w['h'] + $index * 20 + 10;
        $this->clickAt($w['x'] + $w['w'] / 2, $rowY);
    }

    /** The id of the currently focused widget (real-window focus state). */
    public function focusedId(): ?string
    {
        return $this->backend->focusedId();
    }

    /** Current highlight index for a list/select (real-window browse state). */
    public function highlightIndex(string $id): int
    {
        return $this->backend->highlightIndex($id);
    }

    /** Delete the character before the cursor (real Backspace key). */
    public function backspace(): void
    {
        $this->backend->injectKey("\x08");
        $this->backend->step();
    }

    /** Move the insert cursor one position left (real ArrowLeft key). */
    public function cursorLeft(): void
    {
        $this->backend->injectKey("\x01");
        $this->backend->step();
    }

    /** Move the insert cursor one position right (real ArrowRight key). */
    public function cursorRight(): void
    {
        $this->backend->injectKey("\x02");
        $this->backend->step();
    }

    /** Current text of a field's edit buffer (mirrors the real control). */
    public function fieldText(string $id): string
    {
        return $this->backend->fieldText($id);
    }

    /** Current insert position within a field's edit buffer. */
    public function fieldCursor(string $id): int
    {
        return $this->backend->fieldCursor($id);
    }

    /** Toggle a checkbox / radio (by id). $checked true emits 1, false emits 0. */
    public function setChecked(string $id, bool $checked): void
    {
        $w = Snapshot::findById($this->snapshot(), $id);
        if (!$w || ($w['role'] !== 'checkbox' && $w['role'] !== 'radio')) {
            throw new \RuntimeException("no checkbox/radio with id {$id}");
        }
        $this->dispatchHandler($w, $checked ? 1 : 0);
    }

    /** Move a slider (by id) to $value and emit its onChange. */
    public function slideTo(string $id, int $value): void
    {
        $w = Snapshot::findById($this->snapshot(), $id);
        if (!$w || $w['role'] !== 'slider') {
            throw new \RuntimeException("no slider with id {$id}");
        }
        $this->dispatchHandler($w, $value);
    }

    /** Select an option in a dropdown (by id) by index and emit its onChange. */
    public function selectOption(string $id, int $index): void
    {
        $w = Snapshot::findById($this->snapshot(), $id);
        if (!$w || $w['role'] !== 'select') {
            throw new \RuntimeException("no select with id {$id}");
        }
        $this->dispatchHandler($w, $index);
    }

    /** Select a row in a list (by id) by index and emit its onSelect. */
    public function selectListItem(string $id, int $index): void
    {
        $w = Snapshot::findById($this->snapshot(), $id);
        if (!$w || $w['role'] !== 'list') {
            throw new \RuntimeException("no list with id {$id}");
        }
        $this->dispatchHandler($w, $index);
    }

    /** Toggle a switch (by id). $on true emits 1, false emits 0. */
    public function setToggle(string $id, bool $on): void
    {
        $w = Snapshot::findById($this->snapshot(), $id);
        if (!$w || $w['role'] !== 'toggle') {
            throw new \RuntimeException("no toggle with id {$id}");
        }
        $this->dispatchHandler($w, $on ? 1 : 0);
    }

    /** Select a segment (by id) by index and emit its onChange. */
    public function setSegmented(string $id, int $index): void
    {
        $w = Snapshot::findById($this->snapshot(), $id);
        if (!$w || $w['role'] !== 'segmented') {
            throw new \RuntimeException("no segmented control with id {$id}");
        }
        $this->dispatchHandler($w, $index);
    }

    /** Move a split divider (by id) to $percent (0..100) and emit its onChange. */
    public function setSplit(string $id, int $percent): void
    {
        $w = Snapshot::findById($this->snapshot(), $id);
        if (!$w || $w['role'] !== 'split') {
            throw new \RuntimeException("no split with id {$id}");
        }
        $this->dispatchHandler($w, max(5, min(95, $percent)));
    }

    private function dispatchHandler(array $w, mixed $payload): void
    {
        $msg = $w['handler'] ?? null;
        if (!is_string($msg)) {
            throw new \RuntimeException("widget {$w['id']} has no handler");
        }
        $this->app->dispatch($msg, $payload);
    }

    /** Advance the headless event loop a few iterations. */
    public function step(int $n = 1): void
    {
        for ($i = 0; $i < $n; $i++) {
            $this->backend->step();
        }
    }

    /** Begin recording an automation session. */
    public function recorder(): Recorder
    {
        return new Recorder($this);
    }

    /** Replay a previously recorded (or hand-written) script. */
    public function replay(Script $script): void
    {
        foreach ($script->actions() as $a) {
            switch ($a['type']) {
                case 'click_id':
                    $this->clickById($a['target']);
                    break;
                case 'click_text':
                    $this->clickText($a['target']);
                    break;
                case 'dispatch':
                    $this->dispatch($a['msg']);
                    break;
                default:
                    throw new \RuntimeException("unknown automation action: {$a['type']}");
            }
        }
    }

}
