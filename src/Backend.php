<?php
declare(strict_types=1);

namespace Yangweijie\Ui3;

/**
 * A rendering backend. Concrete backends: Native (PHP FFI -> libui3) and
 * Headless (pure PHP, for tests / automation without a display).
 */
interface Backend
{
    /** Build native widgets for the tree and wire click dispatch. */
    public function mount(Element $root, \Closure $dispatch): void;

    /** Push a fresh view tree (after a model change). */
    public function update(Element $root): void;

    /** Advance the event loop one iteration; return 1 if it should quit. */
    public function step(): int;

    /** Enter the blocking event loop. */
    public function run(): void;

    public function quit(): void;

    /** Whether this backend is offscreen (no real window / no event loop). */
    public function isHeadless(): bool;
}
