<?php

declare(strict_types=1);

namespace Yangweijie\Ui3\System\Mcp;

/**
 * Minimal, dependency-free Model Context Protocol (MCP) server adapter.
 *
 * S2 from the observability/automation design: a protocol layer on top of the
 * automation server. It ONLY consumes the contracts exposed by the host —
 * the snapshot tree (via rootsProvider), app state (via stateProvider) and the
 * drive handler — and exposes them as standard MCP tools / resources. It never
 * reaches into widget internals, keeping the agent decoupled from the backend.
 *
 * The transport is the JSON-RPC 2.0 subset used by the MCP "Streamable HTTP"
 * transport: a single POST per request carrying a JSON-RPC envelope and
 * returning a JSON-RPC envelope. No SSE streaming is required because every
 * tool is request/response.
 *
 * Pure by design: {@see handle()} takes a raw JSON-RPC 2.0 string (or a batch
 * array) and returns a raw JSON-RPC 2.0 string, so it is trivially testable
 * without sockets or FFI. The AutomationServer simply mounts it on /mcp.
 */
final class McpServer
{
    /** Protocol version advertised to MCP clients (2024-11-05 = stable). */
    private const PROTOCOL_VERSION = '2024-11-05';

    /** @var array<string, Tool> */
    private array $tools = [];

    /** @var array<string, array{uri:string,name:string,description:string,mimeType:string}> */
    private array $resources = [];

    /**
     * @param callable(): iterable         $rootsProvider Returns roots for the snapshot (each is an array tree).
     * @param callable(string,array):array $driveHandler  (nodeId, payload) => response array.
     * @param (callable(): ?array)|null    $stateProvider Returns a state array (optional).
     */
    public function __construct(
        private $rootsProvider,
        private $driveHandler,
        private $stateProvider = null,
    ) {
        $this->registerBuiltins();
    }

    /**
     * Handle a raw JSON-RPC 2.0 request (object) or batch (array) and return the
     * raw JSON-RPC 2.0 response string. Returns an empty string for a single
     * notification (which, per JSON-RPC, must produce no response) — the HTTP
     * layer maps that to a 202 with no body.
     */
    public function handle(string $raw): string
    {
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            return json_encode($this->error(null, -32700, 'Parse error'));
        }

        if (array_is_list($decoded)) {
            $out = [];
            foreach ($decoded as $msg) {
                $r = $this->dispatchOne($msg);
                if ($r !== null) {
                    $out[] = $r;
                }
            }

            return $out === [] ? '' : json_encode($out);
        }

        $r = $this->dispatchOne($decoded);

        return $r === null ? '' : json_encode($r);
    }

    // --- JSON-RPC dispatch -------------------------------------------------

    /**
     * @param mixed $msg
     * @return array{jsonrpc:string,id:mixed,result:mixed}|array{jsonrpc:string,id:mixed,error:array}|null
     */
    private function dispatchOne(mixed $msg): ?array
    {
        if (! is_array($msg) || ! isset($msg['method'])) {
            $id = (is_array($msg) && array_key_exists('id', $msg)) ? $msg['id'] : null;

            return $this->error($id, -32600, 'Invalid Request');
        }

        $method = (string) $msg['method'];
        $params = $msg['params'] ?? [];
        $id = $msg['id'] ?? null;
        $isNotification = ! array_key_exists('id', $msg);

        if ($method === 'initialize') {
            return $this->result($id, [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'capabilities' => [
                    'tools' => new \stdClass(),
                    'resources' => new \stdClass(),
                ],
                'serverInfo' => [
                    'name' => 'ui3-automation',
                    'version' => '1.0.0',
                ],
                'instructions' => 'This server exposes the running UI as an accessibility '
                    . 'tree (ui_snapshot), the application state (ui_get_state), and a drive '
                    . 'action (ui_drive). Node ids come from ui_snapshot.',
            ]);
        }

        if ($method === 'ping') {
            return $this->result($id, []);
        }

        if ($isNotification) {
            return null;
        }

        if ($method === 'tools/list') {
            return $this->result($id, ['tools' => $this->toolList()]);
        }

        if ($method === 'tools/call') {
            $name = (string) ($params['name'] ?? '');
            $args = $params['arguments'] ?? [];
            if (! is_array($args)) {
                $args = [];
            }

            return $this->result($id, $this->callTool($name, $args));
        }

        if ($method === 'resources/list') {
            return $this->result($id, ['resources' => array_values($this->resources)]);
        }

        if ($method === 'resources/read') {
            $uri = (string) ($params['uri'] ?? '');

            return $this->result($id, $this->readResource($uri));
        }

        return $this->error($id, -32601, "Method not found: {$method}");
    }

    // --- Tool / resource handling -----------------------------------------

    /** @return list<array{name:string,description:string,inputSchema:array}> */
    private function toolList(): array
    {
        $out = [];
        foreach ($this->tools as $tool) {
            $out[] = [
                'name' => $tool->name,
                'description' => $tool->description,
                'inputSchema' => $tool->inputSchema,
            ];
        }

        return $out;
    }

    /** @return array{content:list<array{type:string,text:string}>,isError:bool} */
    private function callTool(string $name, array $args): array
    {
        $tool = $this->tools[$name] ?? null;
        if ($tool === null) {
            return $this->toolError("Unknown tool: {$name}");
        }

        try {
            $payload = ($tool->handler)($args);
        } catch (\Throwable $e) {
            return $this->toolError($e->getMessage());
        }

        if (! is_array($payload)) {
            $payload = ['result' => $payload];
        }

        if (array_key_exists('_error', $payload)) {
            return $this->toolError((string) $payload['_error']);
        }

        return [
            'content' => [
                ['type' => 'text', 'text' => json_encode($payload, JSON_UNESCAPED_SLASHES)],
            ],
            'isError' => false,
        ];
    }

    /** @return array{content:list<array{type:string,text:string}>,isError:bool} */
    private function toolError(string $message): array
    {
        return [
            'content' => [
                ['type' => 'text', 'text' => $message],
            ],
            'isError' => true,
        ];
    }

    /** @return array{contents:list<array{uri:string,mimeType:string,text:string}>} */
    private function readResource(string $uri): array
    {
        if (! isset($this->resources[$uri])) {
            return ['contents' => []];
        }

        if ($uri === 'ui://snapshot') {
            return [
                'contents' => [[
                    'uri' => $uri,
                    'mimeType' => 'application/json',
                    'text' => json_encode($this->buildSnapshot(), JSON_UNESCAPED_SLASHES),
                ]],
            ];
        }

        return ['contents' => []];
    }

    // --- contract consumption ---------------------------------------------

    /**
     * Build the `notifications/resources/updated` JSON-RPC notification for the
     * live snapshot resource. A client that receives this should re-read
     * `ui://snapshot`.
     *
     * @return array{jsonrpc:string,method:string,params:array{uri:string}}
     */
    public function resourceUpdatedNotification(): array
    {
        return [
            'jsonrpc' => '2.0',
            'method' => 'notifications/resources/updated',
            'params' => ['uri' => 'ui://snapshot'],
        ];
    }

    /**
     * Build the `notifications/state_changed` JSON-RPC notification carrying the
     * new application state.
     *
     * @param array<string, mixed> $state
     * @return array{jsonrpc:string,method:string,params:array{state:array<string,mixed>}}
     */
    public function stateChangedNotification(array $state): array
    {
        return [
            'jsonrpc' => '2.0',
            'method' => 'notifications/state_changed',
            'params' => ['state' => $state],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function buildSnapshot(): array
    {
        $out = [];
        foreach (($this->rootsProvider)() as $root) {
            if (is_array($root)) {
                $out[] = $root;
            }
        }

        return $out;
    }

    // --- JSON-RPC envelope helpers ----------------------------------------



    /** @param mixed $id */
    private function result($id, array $payload): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $payload,
        ];
    }

    /** @param mixed $id */
    private function error($id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ];
    }

    // --- Built-in tools / resources ---------------------------------------

    private function registerBuiltins(): void
    {
        $emptySchema = ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];

        $this->tools['ui_snapshot'] = new Tool(
            'ui_snapshot',
            'Read the live accessibility tree of the running UI. '
            . 'Returns windows with id / role / label / value / state / geometry and their children.',
            $emptySchema,
            fn (array $args): array => $this->buildSnapshot(),
        );

        $this->tools['ui_get_state'] = new Tool(
            'ui_get_state',
            'Read the application state snapshot, if a state provider is registered.',
            $emptySchema,
            function (array $args): array {
                if ($this->stateProvider === null) {
                    return ['_error' => 'no state provider registered'];
                }
                $state = ($this->stateProvider)();

                return $state === null ? ['_error' => 'no state'] : $state;
            },
        );

        $this->tools['ui_drive'] = new Tool(
            'ui_drive',
            'Invoke an action on a UI node by its id (from ui_snapshot). The actual '
            . 'effect is delegated to the app\'s drive handler.',
            [
                'type' => 'object',
                'properties' => [
                    'nodeId' => [
                        'type' => 'string',
                        'description' => 'Id of the node to drive (from ui_snapshot).',
                    ],
                    'payload' => [
                        'type' => 'object',
                        'description' => 'Optional action + args: {action:"click"|"click_text"|"set_value"|"focus"|"tab"|"dispatch", text?, value?, msg?, payload?}.',
                        'additionalProperties' => true,
                    ],
                ],
                'required' => ['nodeId'],
                'additionalProperties' => false,
            ],
            function (array $args): array {
                $nodeId = $args['nodeId'] ?? null;
                if (! is_string($nodeId)) {
                    return ['_error' => 'nodeId (string) required'];
                }
                $payload = is_array($args['payload'] ?? null) ? $args['payload'] : [];

                return ($this->driveHandler)($nodeId, $payload);
            },
        );

        $this->resources['ui://snapshot'] = [
            'uri' => 'ui://snapshot',
            'name' => 'UI Snapshot',
            'description' => 'Live accessibility tree of the running UI.',
            'mimeType' => 'application/json',
        ];
    }
}
