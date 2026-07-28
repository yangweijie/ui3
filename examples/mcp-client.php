<?php

/**
 * Minimal MCP client for the ui3 automation server (POST /mcp, JSON-RPC 2.0).
 *
 * Dependency-free and display-free: it speaks plain HTTP to a *running* ui3 app
 * that has automation enabled (see examples/login_form.php under
 * UI3_REAL_WINDOW=1) and drives it the same way an AI agent would. It does NOT
 * need the FFI extension or a window of its own.
 *
 * Full round-trip demonstrated against the login form:
 *   initialize → tools/list → ui_snapshot → ui_drive (set_value x2, click) → ui_get_state
 *   and asserts the visible "Welcome, …" label appears.
 *
 * Transport note: ui3's /mcp is request/response only (no SSE), so every call is
 * a single POST that opens, gets a JSON-RPC envelope, and closes — no streaming.
 *
 * Usage:
 *   # terminal 1 (real window; starts the MCP server on :8080):
 *   UI3_REAL_WINDOW=1 bash bin/run.sh php -d ffi.enable=true examples/login_form.php
 *   # terminal 2:
 *   php examples/mcp-client.php                  # defaults to http://127.0.0.1:8080
 *   php examples/mcp-client.php http://127.0.0.1:9000
 */

declare(strict_types=1);

/**
 * JSON-RPC 2.0 over HTTP client for the POST /mcp endpoint. Each call opens a
 * fresh connection (the server answers with HTTP/1.0 + Content-Length and
 * closes), which is fine for request/response MCP calls.
 */
final class McpHttpClient
{
    public function __construct(private string $url) {}

    /** Send a JSON-RPC request that expects a response (carries an `id`). */
    public function call(string $method, array $params = []): ?array
    {
        static $id = 0;
        $id++;

        return $this->post([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => $params,
        ]);
    }

    /** Send a JSON-RPC notification (no `id`) — the server replies 202 with no body. */
    public function notify(string $method, array $params = []): void
    {
        $this->post([
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
        ]);
    }

    private function post(array $payload): ?array
    {
        $body = (string) json_encode($payload);
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
                'content' => $body,
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($this->url, false, $ctx);
        if ($raw === false || $raw === '') {
            return null; // notification (202, empty body) or connection error
        }

        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }
}

/** Extract the text payload from a tools/call result envelope. */
function mcp_text(?array $resp): ?string
{
    return $resp['result']['content'][0]['text'] ?? null;
}

/** Drive one node via the ui_drive tool and echo the result. */
function drive(McpHttpClient $http, string $nodeId, array $payload): array
{
    $r = $http->call('tools/call', [
        'name' => 'ui_drive',
        'arguments' => ['nodeId' => $nodeId, 'payload' => $payload],
    ]);
    $text = mcp_text($r) ?? json_encode($r, JSON_UNESCAPED_SLASHES);
    echo "  → drove {$nodeId} (" . ($payload['action'] ?? '?') . "): {$text}\n";

    return json_decode($text, true) ?? [];
}

// ── bootstrap ────────────────────────────────────────────────────────────────

$base = $argv[1] ?? 'http://127.0.0.1:8080';
$mcpUrl = rtrim($base, '/') . '/mcp';
echo "MCP client → {$mcpUrl}\n";

$http = new McpHttpClient($mcpUrl);

// 1) handshake -----------------------------------------------------------------
$init = $http->call('initialize', [
    'protocolVersion' => '2024-11-05',
    'capabilities' => new stdClass(),
    'clientInfo' => ['name' => 'ui3-php-client', 'version' => '1.0.0'],
]);
if ($init === null) {
    fwrite(STDERR, "Handshake failed — is the server running at {$base}?\n");
    exit(1);
}
$info = $init['result']['serverInfo'] ?? [];
echo 'initialize → server: ' . ($info['name'] ?? '?') . ' v' . ($info['version'] ?? '?') . "\n";
$http->notify('notifications/initialized');

// 2) discover tools -------------------------------------------------------------
$tools = $http->call('tools/list');
$toolNames = array_map(static fn (array $t): string => $t['name'], $tools['result']['tools'] ?? []);
echo 'tools/list → ' . implode(', ', $toolNames) . "\n";

// 3) read the live accessibility tree (ui_snapshot returns a list with one window) ─
$snap = $http->call('tools/call', ['name' => 'ui_snapshot', 'arguments' => new stdClass()]);
$roots = json_decode(mcp_text($snap) ?: '[]', true);
$win = is_array($roots) ? ($roots[0] ?? null) : null;
$widgets = $win['widgets'] ?? [];
echo 'ui_snapshot → ' . count($widgets) . " widget(s)\n";

// 4) drive: fill the form and click Login (set_value goes through onInput, not keys) ─
echo "driving login form:\n";
drive($http, 'user-in', ['action' => 'set_value', 'value' => 'alice']);
drive($http, 'pass-in', ['action' => 'set_value', 'value' => 'secret']);
drive($http, 'login-btn', ['action' => 'click']);

// 5) assert the welcome outcome --------------------------------------------------
$state = $http->call('tools/call', ['name' => 'ui_get_state', 'arguments' => new stdClass()]);
$model = json_decode(mcp_text($state) ?: '[]', true);
$loggedIn = ($model['logged_in'] ?? false) === true;
$user = (string) ($model['user'] ?? '');

// also confirm the *visible* welcome label (not just the model) from a fresh snapshot
$snap2 = $http->call('tools/call', ['name' => 'ui_snapshot', 'arguments' => new stdClass()]);
$roots2 = json_decode(mcp_text($snap2) ?: '[]', true);
$win2 = is_array($roots2) ? ($roots2[0] ?? null) : null;
$welcome = '';
foreach (($win2['widgets'] ?? []) as $w) {
    if (($w['role'] ?? '') === 'label' && str_starts_with((string) ($w['name'] ?? ''), 'Welcome')) {
        $welcome = (string) $w['name'];
    }
}

echo "\n--- assertion ---\n";
if ($loggedIn && $user === 'alice' && $welcome !== '') {
    echo "PASS: logged in as '{$user}', welcome label = '{$welcome}'\n";
    exit(0);
}
fwrite(STDERR, "FAIL: logged_in=" . var_export($loggedIn, true)
    . " user='{$user}' welcome='{$welcome}'\n");
exit(1);
