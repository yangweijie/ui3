<?php

declare(strict_types=1);

namespace Yangweijie\Ui3;

use Yangweijie\Ui3\Automation\Automation;
use Yangweijie\Ui3\Extensions;
use Yangweijie\Ui3\System\AutomationServer;
use Yangweijie\Ui3\Ticker;
use Yangweijie\Ui3\Windows;

/**
 * Elm-style application runtime (mirrors native's Model / Msg / update / view).
 *
 * - model : immutable application state
 * - update(model, msg) -> model : the ONLY place state changes
 * - view(model) -> Element : pure description of the UI
 * - messages are dispatched by widgets (button onClick) and flow back via update
 */
final class App
{
    private mixed $model = null;
    private ?Backend $backend = null;

    private bool $automationEnabled = false;
    private int $automationPort = 0;
    private bool $automationMcp = true;
    private ?AutomationServer $automationServer = null;

    /** Active design tokens (Theme name or raw token array), applied to the backend on run(). */
    private mixed $theme = null;

    /** Multi-window state (open/close/focus), mirrors native's window_state. */
    private Windows $windows;

    /** Extension hook bus (mirrors native's extensions/). */
    private Extensions $extensions;

    /** Headless frame-driver config (used when the backend has no native loop). */
    private ?int $headlessFrames = null;
    private float $headlessFps = 60.0;
    private ?float $headlessDuration = null;
    /** @var ?callable(float $t, Backend $backend):void */
    private $headlessOnFrame = null;
    private ?\Closure $clock = null;

    /**
     * @param mixed    $init   initial model, or a callable () => model
     * @param \Closure $update (model, msg) => model
     * @param \Closure $view   (model) => Element
     */
    public function __construct(
        private mixed $init,
        private \Closure $update,
        private \Closure $view,
    ) {
        $this->windows = new Windows();
        $this->extensions = new Extensions();
    }

    /**
     * Enable the embedded automation server (REST + MCP) once run() starts, so an
     * external AI agent / test driver can connect to the running window and read
     * its accessibility tree, read state, and drive it — without any OS event
     * synthesis. Only takes effect for a real (non-headless) window.
     */
    public function enableAutomation(int $port = 0, bool $mcp = true): self
    {
        $this->automationEnabled = true;
        $this->automationPort = $port;
        $this->automationMcp = $mcp;
        return $this;
    }

    /**
     * Apply a design theme to the running window. Accepts a Theme name
     * (Theme::LIGHT / Theme::DARK) or a raw token array. The backend resolves
     * it into concrete colors/fonts on every paint.
     */
    public function withTheme(string|array $theme): self
    {
        $this->theme = $theme;
        return $this;
    }

    public function model(): mixed
    {
        return $this->model;
    }

    public function start(): mixed
    {
        $this->model = is_callable($this->init) ? ($this->init)() : $this->init;
        return $this->model;
    }

    public function dispatch(string $msg, mixed $payload = null): mixed
    {
        $argc = (new \ReflectionFunction($this->update))->getNumberOfParameters();
        if ($payload === null || $argc < 3) {
            $this->model = ($this->update)($this->model, $msg);
        } else {
            $this->model = ($this->update)($this->model, $msg, $payload);
        }
        if ($this->backend) {
            $this->backend->update($this->render());
        }
        $this->extensions->trigger('afterUpdate', $this->model);
        if ($this->automationServer !== null) {
            $this->automationServer->notifyStateChanged();
        }
        return $this->model;
    }

    public function render(): Element
    {
        $this->extensions->trigger('beforeRender', $this->model);
        $el = ($this->view)($this->model);
        $this->extensions->trigger('afterRender', $el);
        return $el;
    }

    /** Register an extension hook at a lifecycle point (beforeRender/afterRender/afterUpdate). */
    public function extend(string $point, callable $hook): self
    {
        $this->extensions->register($point, $hook);
        return $this;
    }

    public function extensions(): Extensions
    {
        return $this->extensions;
    }

    /** Open an additional window (registered in window state); renders on a real host. */
    public function openWindow(string $id, string $title, int $width = 320, int $height = 240): self
    {
        $this->windows->open($id, $title, $width, $height);
        return $this;
    }

    public function closeWindow(string $id): self
    {
        $this->windows->close($id);
        return $this;
    }

    public function focusWindow(string $id): self
    {
        $this->windows->focus($id);
        return $this;
    }

    /** The window-state manager. */
    public function windows(): Windows
    {
        return $this->windows;
    }

    public function activeWindow(): ?string
    {
        return $this->windows->active();
    }

    public function run(?Backend $backend = null): void
    {
        $backend ??= new Backends\Canvas();
        $this->start();
        $this->backend = $backend;
        if ($this->theme !== null && method_exists($backend, 'setTheme')) {
            $backend->setTheme($this->theme);
        }
        $backend->mount($this->render(), fn(string $msg, mixed $payload = null) => $this->dispatch($msg, $payload));

        if ($this->automationEnabled && !$backend->isHeadless()) {
            $auto = new Automation($this, $backend);
            $server = new AutomationServer($auto, fn() => $this->model(), $this->automationMcp);
            $server->start($this->automationPort);
            $this->automationServer = $server;
            // Custom event loop: pump the OS event queue AND the automation
            // socket on the same GUI thread, so an AI driver can reach the app.
            while ($backend->step() !== 0) {
                $server->poll();
                \usleep(2000);
            }
            $server->stop();
            return;
        }
        if ($backend->isHeadless()) {
            $this->runHeadlessLoop();
            return;
        }
        $backend->run();
    }

    /**
     * Configure a headless (no native event loop) frame driver. When the
     * backend reports isHeadless(), run() uses a Ticker instead of the
     * backend's own loop, advancing the animation clock and repainting each
     * frame. Without $durationSec it runs until the UI stops animating (or
     * $frames cap is hit); pass $onFrame to capture/inspect each frame.
     *
     * @param ?callable(float $t, Backend $backend):void $onFrame
     */
    public function headless(int $frames, float $fps = 60.0, ?float $durationSec = null, ?callable $onFrame = null): self
    {
        $this->headlessFrames = $frames;
        $this->headlessFps = $fps;
        $this->headlessDuration = $durationSec;
        $this->headlessOnFrame = $onFrame;
        return $this;
    }

    /** Inject a clock source (seconds) so the headless loop is deterministic in tests. */
    public function withClock(\Closure $now): self
    {
        $this->clock = $now;
        return $this;
    }

    /**
     * Feed an IME composition event for a field (start/update/end). For a
     * headless backend this injects the preview state and repaints once so the
     * change is visible without a native event loop. Native backends wire this
     * to the platform's composition callbacks.
     */
    public function composition(string $id, string $phase, string $text): void
    {
        if ($this->backend !== null && method_exists($this->backend, 'composition')) {
            $this->backend->composition($id, $phase, $text);
        }
        if ($this->backend !== null && $this->backend->isHeadless() && method_exists($this->backend, 'update')) {
            $this->backend->update($this->render());
        }
    }

    private function runHeadlessLoop(): void
    {
        $backend = $this->backend;
        $ticker = new Ticker($this->clock);
        $maxFrames = $this->headlessFrames ?? 0;
        $duration = $this->headlessDuration ?? 0.0;
        if ($duration <= 0.0 && $maxFrames <= 0) {
            $duration = $this->computeAnimDuration();
        }
        // Render the tree ONCE and reuse the same Element objects every frame.
        // Animation start times key off element identity (spl_object_id), so a
        // fresh tree each frame would reset the clock to t=0 every time.
        $root = $this->render();
        $onFrame = function (float $t) use ($backend, $root, $maxFrames, $duration, $ticker): bool {
            if (method_exists($backend, 'setClock')) {
                $backend->setClock($t);
            }
            $backend->update($root);
            if ($this->headlessOnFrame !== null) {
                ($this->headlessOnFrame)($t, $backend);
            }
            if ($maxFrames > 0 && $ticker->frames >= $maxFrames) {
                return false;
            }
            if ($duration > 0.0 && $t >= $duration) {
                return false;
            }
            if ($duration <= 0.0 && method_exists($backend, 'isAnimating') && !$backend->isAnimating()) {
                return false;
            }
            return true;
        };
        $ticker->run($onFrame, $duration > 0.0 ? $duration : 30.0, $this->headlessFps);
    }

    /** Longest animation duration (+delay) across the current view, in seconds. */
    private function computeAnimDuration(): float
    {
        $max = 0.0;
        $walk = function (Element $el) use (&$walk, &$max): void {
            $anim = $el->prop('anim');
            if (is_array($anim)) {
                foreach ($anim as $s) {
                    $max = max($max, (float)($s['duration'] ?? 1000) + (float)($s['delay'] ?? 0));
                }
            }
            foreach ($el->children as $c) {
                if ($c instanceof Element) {
                    $walk($c);
                }
            }
        };
        $walk($this->render());
        return $max > 0.0 ? $max / 1000.0 + 0.1 : 0.0;
    }
}
