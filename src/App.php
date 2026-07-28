<?php

declare(strict_types=1);

namespace Yangweijie\Ui3;

use Yangweijie\Ui3\Automation\Automation;
use Yangweijie\Ui3\System\AutomationServer;

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

    /**
     * @param mixed    $init   initial model, or a callable () => model
     * @param \Closure $update (model, msg) => model
     * @param \Closure $view   (model) => Element
     */
    public function __construct(
        private mixed $init,
        private \Closure $update,
        private \Closure $view,
    ) {}

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
        if ($this->automationServer !== null) {
            $this->automationServer->notifyStateChanged();
        }
        return $this->model;
    }

    public function render(): Element
    {
        return ($this->view)($this->model);
    }

    public function run(?Backend $backend = null): void
    {
        $backend ??= new Backends\Canvas();
        $this->start();
        $this->backend = $backend;
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
        $backend->run();
    }
}
