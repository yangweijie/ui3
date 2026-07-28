<?php
declare(strict_types=1);

namespace Yangweijie\Ui3\Backends;

use Yangweijie\Ui3\{Backend, Element};

/**
 * Pure-PHP backend used for tests and automation: it never opens a window.
 * It records mount/update/click events so tests can assert on behaviour, and
 * exposes click() to simulate a button press driving a message into the app.
 */
final class Headless implements Backend
{
    private ?Element $root = null;
    private ?\Closure $dispatch = null;
    private bool $running = false;

    /** @var list<array{type:string,msg?:string}> */
    public array $events = [];

    public function mount(Element $root, \Closure $dispatch): void
    {
        $this->root = $root;
        $this->dispatch = $dispatch;
        $this->events[] = ['type' => 'mount'];
    }

    public function update(Element $root): void
    {
        $this->root = $root;
        $this->events[] = ['type' => 'update'];
    }

    public function step(): int
    {
        return $this->running ? 0 : 1;
    }

    public function run(): void
    {
        $this->running = true;
        $this->events[] = ['type' => 'run'];
    }

    public function quit(): void
    {
        $this->running = false;
        $this->events[] = ['type' => 'quit'];
    }

    /** The headless backend is always offscreen. */
    public function isHeadless(): bool
    {
        return true;
    }

    /** Simulate a button click by its onClick message. */
    public function click(string $msg): void
    {
        $this->events[] = ['type' => 'click', 'msg' => $msg];
        ($this->dispatch)($msg);
    }

    public function root(): ?Element
    {
        return $this->root;
    }
}
