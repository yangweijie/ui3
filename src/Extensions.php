<?php

declare(strict_types=1);

namespace Yangweijie\Ui3;

/**
 * Extension registry — a tiny hook bus (mirrors native's extensions/). Extensions
 * register callbacks at named lifecycle points (e.g. 'beforeRender',
 * 'afterUpdate'); the runtime triggers them so add-ons can observe or mutate
 * the view/model without patching core.
 */
final class Extensions
{
    /** @var array<string,array<int,callable>> */
    private array $hooks = [];

    public function register(string $point, callable $hook): void
    {
        $this->hooks[$point][] = $hook;
    }

    /** Invoke every hook registered at $point, forwarding any arguments. */
    public function trigger(string $point, mixed ...$args): void
    {
        foreach ($this->hooks[$point] ?? [] as $hook) {
            $hook(...$args);
        }
    }

    public function has(string $point): bool
    {
        return !empty($this->hooks[$point]);
    }
}
