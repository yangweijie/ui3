<?php

declare(strict_types=1);

namespace Yangweijie\Ui3\System\Mcp;

/**
 * A single MCP tool definition (name + description + JSON-Schema input + handler).
 *
 * Kept as a tiny value object so McpServer can build its tool list without
 * reaching into any widget internals — it only knows callables.
 */
final class Tool
{
    public function __construct(
        public string $name,
        public string $description,
        public array $inputSchema,
        public \Closure $handler,
    ) {
    }
}
