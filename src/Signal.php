<?php

declare(strict_types=1);

namespace Yangweijie\Ui3;

/**
 * Signal — a minimal reactive value holder (signals-lite). Setters notify
 * subscribers; `update()` derives a new value from the current one. This is the
 * state primitive the Elm-style runtime composes views from.
 */
final class Signal
{
    private mixed $value;

    /** @var array<int, callable(mixed):void> */
    private array $subs = [];

    public function __construct(mixed $value = null)
    {
        $this->value = $value;
    }

    public function get(): mixed
    {
        return $this->value;
    }

    public function set(mixed $value): void
    {
        if ($value === $this->value) {
            return;
        }
        $this->value = $value;
        foreach ($this->subs as $cb) {
            $cb($value);
        }
    }

    /** Derive and assign a new value from the current one. */
    public function update(callable $fn): void
    {
        $this->set($fn($this->value));
    }

    public function subscribe(callable $cb): void
    {
        $this->subs[] = $cb;
    }
}
