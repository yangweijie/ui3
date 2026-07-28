<?php

declare(strict_types=1);

namespace Yangweijie\Ui3\System;

use Yangweijie\Ui3\Automation\Automation;
use Yangweijie\Ui3\System\Mcp\McpServer;

/**
 * Embedded local automation server — the AI / test-driver hook point.
 *
 * Listens on 127.0.0.1 only (never exposed to the network) and serves:
 *
 *   GET  /snapshot  -> accessibility tree (Automation::snapshot)
 *   GET  /state     -> application model
 *   POST /drive     -> invoke an action on the app (click / set_value / focus / tab / dispatch)
 *   POST /mcp       -> MCP JSON-RPC (ui_snapshot / ui_get_state / ui_drive)
 *
 * The server is pure PHP (stream_socket_server + a non-blocking poll() driven
 * by App::run's event loop), so all work happens on the GUI thread — no extra
 * async runtime and no touching the FFI extension from another thread. Every
 * request is wrapped in try/catch because an uncaught exception would cross the
 * FFI / event-loop boundary and crash the process.
 *
 * The server is deliberately decoupled: it only consumes the Automation facade
 * (snapshot + drive + model) and forwards write actions to it. It never reaches
 * into widget internals.
 */
final class AutomationServer
{
    /** @var resource|null The listening server socket. */
    private $server = null;

    /** @var array<int,resource> Active client connections, keyed by (int) resource. */
    private array $conns = [];

    /** @var array<int,string> Per-connection read buffers, keyed by (int) resource. */
    private array $buffers = [];

    private int $port = 0;

    /** When true, an MCP protocol adapter is mounted at POST /mcp. */
    private ?McpServer $mcpServer = null;

    /**
     * @param callable(): ?array|null $stateProvider Returns the app model for /state (optional).
     */
    public function __construct(
        private Automation $auto,
        private $stateProvider = null,
        private bool $mcp = true,
    ) {
        if ($this->mcp) {
            $this->mcpServer = new McpServer(
                fn() => [$this->auto->snapshot()],
                fn($nodeId, $payload) => $this->drive($nodeId, $payload),
                $this->stateProvider,
            );
        }
    }

    /**
     * Bind the socket. Call from App::run (GUI thread) before entering the loop.
     */
    public function start(int $port = 0): self
    {
        $addr = $port === 0 ? '127.0.0.1:0' : "127.0.0.1:{$port}";
        $this->server = @\stream_socket_server(
            "tcp://{$addr}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        );
        if ($this->server === false) {
            throw new \RuntimeException("AutomationServer: failed to bind {$addr}: {$errstr} ({$errno})");
        }
        \stream_set_blocking($this->server, false);
        $this->port = $this->detectPort();

        return $this;
    }

    /** The bound port (useful when port 0 let the OS pick one). */
    public function port(): int
    {
        return $this->port;
    }

    /** Stop the server and close all connections. Safe to call multiple times. */
    public function stop(): void
    {
        foreach (array_keys($this->conns) as $id) {
            $this->dropConn($id);
        }
        if ($this->server !== null) {
            @\fclose($this->server);
            $this->server = null;
        }
    }

    public function __destruct()
    {
        $this->stop();
    }

    /**
     * One poll tick: accept new connections, read buffered requests, respond.
     * Driven by App::run's event loop (every frame). Always returns true so the
     * loop keeps firing until stop() is called. Never throws across the boundary.
     */
    public function poll(): bool
    {
        try {
            if ($this->server === null) {
                return false;
            }

            // Non-blocking accept every tick (a zero-timeout stream_select can
            // intermittently miss the listen socket on macOS loopback).
            $conn = @\stream_socket_accept($this->server, 0);
            while ($conn !== false) {
                \stream_set_blocking($conn, false);
                $id = (int) $conn;
                $this->conns[$id] = $conn;
                $this->buffers[$id] = '';
                $conn = @\stream_socket_accept($this->server, 0);
            }

            foreach ($this->conns as $id => $conn) {
                $data = @\fread($conn, 8192);
                if ($data === false) {
                    $this->dropConn($id);
                    continue;
                }
                if ($data === '') {
                    $meta = @\stream_get_meta_data($conn);
                    if (($meta['eof'] ?? false)) {
                        $this->dropConn($id);
                    }
                    continue;
                }
                $this->buffers[$id] .= $data;

                $parsed = $this->tryParseRequest($this->buffers[$id]);
                if ($parsed === null) {
                    continue;
                }

                $this->buffers[$id] = \substr($this->buffers[$id], $parsed['consumed']);
                $body = $this->handleRequest($parsed['method'], $parsed['path'], $parsed['body']);
                // An empty body means "no HTTP response needed" (a JSON-RPC
                // notification); emit a 202 Accepted with no body.
                if ($body === '') {
                    $this->writeFully($conn, $this->httpResponse(202, ''));
                } else {
                    $this->writeFully($conn, $this->httpResponse(200, $body));
                }
                $this->dropConn($id);
            }
        } catch (\Throwable $e) {
            // Swallow: never let an automation error cross the FFI / event loop.
        }

        return true;
    }

    /**
     * Handle a parsed request and return the JSON response BODY (a JSON string),
     * or '' when no body should be sent. Pure (no socket / no FFI), so it is safe
     * to call directly from tests. The HTTP envelope is only added in poll().
     */
    public function handleRequest(string $method, string $path, string $body): string
    {
        try {
            $route = (string) \parse_url($path, PHP_URL_PATH);
            if ($route === '') {
                $route = '/';
            }

            if ($method === 'GET' && $route === '/snapshot') {
                return $this->jsonBody(['windows' => [$this->auto->snapshot()]]);
            }

            if ($method === 'GET' && $route === '/state') {
                if ($this->stateProvider === null) {
                    return $this->jsonBody(['error' => 'no state provider registered']);
                }
                $state = ($this->stateProvider)();
                if ($state === null) {
                    return $this->jsonBody(['error' => 'no state']);
                }

                return $this->jsonBody($state);
            }

            if ($method === 'POST' && $route === '/drive') {
                $payload = $body !== '' ? \json_decode($body, true) : [];
                if (! \is_array($payload)) {
                    return $this->jsonBody(['error' => 'invalid JSON body']);
                }

                return $this->jsonBody($this->drive($payload['nodeId'] ?? null, $payload['payload'] ?? $payload));
            }

            if ($method === 'POST' && $route === '/mcp') {
                if ($this->mcpServer === null) {
                    return $this->jsonBody(['error' => 'MCP not enabled']);
                }
                // McpServer::handle returns a JSON-RPC envelope string, or '' for
                // a notification that must not produce a response.
                return $this->mcpServer->handle($body);
            }

            return $this->jsonBody(['error' => 'not found', 'path' => $route]);
        } catch (\Throwable $e) {
            return $this->jsonBody(['error' => $e->getMessage()]);
        }
    }

    /**
     * Route a drive action onto the Automation facade.
     *
     * @param mixed        $nodeId
     * @param array<mixed> $payload
     * @return array<string, mixed>
     */
    public function drive($nodeId, array $payload): array
    {
        $action = $payload['action'] ?? 'click';
        $id = $payload['nodeId'] ?? $nodeId;
        try {
            switch ($action) {
                case 'click':
                    if (! \is_string($id)) {
                        return ['error' => 'nodeId (string) required'];
                    }
                    $this->auto->clickById($id);
                    break;
                case 'click_text':
                    $text = $payload['text'] ?? '';
                    if (! \is_string($text) || $text === '') {
                        return ['error' => 'text required'];
                    }
                    $this->auto->clickText($text);
                    break;
                case 'set_value':
                    if (! \is_string($id)) {
                        return ['error' => 'nodeId (string) required'];
                    }
                    $this->auto->setValue($id, (string) ($payload['value'] ?? ''));
                    break;
                case 'focus':
                    if (! \is_string($id)) {
                        return ['error' => 'nodeId (string) required'];
                    }
                    $this->auto->focus($id);
                    break;
                case 'tab':
                    $this->auto->tab();
                    break;
                case 'dispatch':
                    $msg = $payload['msg'] ?? '';
                    if (! \is_string($msg) || $msg === '') {
                        return ['error' => 'msg required'];
                    }
                    $this->auto->dispatch($msg, $payload['payload'] ?? null);
                    break;
                default:
                    return ['error' => "unknown action: {$action}"];
            }

            return ['ok' => true, 'model' => $this->auto->model()];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /** State-changed hook (no-op without SSE; reserved for live push). */
    public function notifyStateChanged(): void
    {
    }

    // --- HTTP helpers -------------------------------------------------------

    private function jsonBody(array $data): string
    {
        return (string) \json_encode($data);
    }

    private function httpResponse(int $code, string $json): string
    {
        $reason = self::reasonPhrase($code);
        $len = \strlen($json);

        return "HTTP/1.0 {$code} {$reason}\r\n"
            . "Content-Type: application/json\r\n"
            . "Content-Length: {$len}\r\n"
            . "Connection: close\r\n"
            . "\r\n"
            . $json;
    }

    private static function reasonPhrase(int $code): string
    {
        return match ($code) {
            200 => 'OK',
            202 => 'Accepted',
            400 => 'Bad Request',
            404 => 'Not Found',
            500 => 'Internal Server Error',
            default => 'OK',
        };
    }

    /**
     * Parse one HTTP request out of the buffer. Returns null until the full
     * request (headers + Content-Length body) is available.
     *
     * @return array{method:string,path:string,headers:string,body:string,consumed:int}|null
     */
    private function tryParseRequest(string $buf): ?array
    {
        $headerEnd = \strpos($buf, "\r\n\r\n");
        if ($headerEnd === false) {
            return null;
        }
        $rawHeaders = \substr($buf, 0, $headerEnd);
        $lines = \explode("\r\n", $rawHeaders);
        $reqLine = $lines[0] ?? '';
        if (! \preg_match('#^(GET|POST|PUT|DELETE|HEAD) (\S+) HTTP/1\.[01]$#', $reqLine, $m)) {
            return null;
        }
        $method = $m[1];
        $path = $m[2];

        $contentLength = 0;
        foreach (\array_slice($lines, 1) as $line) {
            if (\preg_match('#^content-length:\s*(\d+)$#i', $line, $cl)) {
                $contentLength = (int) $cl[1];
            }
        }

        $bodyStart = $headerEnd + 4;
        $body = \substr($buf, $bodyStart);
        if (\strlen($body) < $contentLength) {
            return null;
        }

        return [
            'method' => $method,
            'path' => $path,
            'headers' => $rawHeaders,
            'body' => \substr($body, 0, $contentLength),
            'consumed' => $bodyStart + $contentLength,
        ];
    }

    private function dropConn(int $id): void
    {
        if (isset($this->conns[$id])) {
            @\fclose($this->conns[$id]);
            unset($this->conns[$id]);
        }
        unset($this->buffers[$id]);
    }

    /**
     * Write the full payload to a (possibly non-blocking) stream. Writes run in
     * blocking mode so a freshly-accepted socket always drains every byte within
     * the current poll tick; the original blocking mode is restored afterwards.
     * Returns true once every byte is sent, false only on a hard write error.
     */
    private function writeFully($conn, string $data): bool
    {
        $meta = \stream_get_meta_data($conn);
        $wasBlocking = $meta['blocked'] ?? true;
        if (! $wasBlocking) {
            \stream_set_blocking($conn, true);
        }

        $offset = 0;
        $len = \strlen($data);
        $ok = true;
        while ($offset < $len) {
            $written = @\fwrite($conn, \substr($data, $offset));
            if ($written === false || $written === 0) {
                $ok = false;
                break;
            }
            $offset += $written;
        }

        if (! $wasBlocking) {
            \stream_set_blocking($conn, false);
        }

        return $ok;
    }

    private function detectPort(): int
    {
        $name = @\stream_socket_get_name($this->server, false);
        if ($name === false || $name === '') {
            return 0;
        }
        $parts = \explode(':', $name);

        return (int) \end($parts);
    }
}
