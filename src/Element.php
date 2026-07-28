<?php
declare(strict_types=1);

namespace Yangweijie\Ui3;

/**
 * Immutable declarative UI node. The view function returns a tree of these;
 * backends translate them into native widgets.
 */
final class Element
{
    public function __construct(
        public readonly string $type,
        public readonly array $props = [],
        public readonly array $children = [],
    ) {}

    public function prop(string $key, mixed $default = null): mixed
    {
        return $this->props[$key] ?? $default;
    }
}
