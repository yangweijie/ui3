<?php

declare(strict_types=1);

use Yangweijie\Ui3\System\Mcp\McpServer;

/**
 * MCP adapter is pure JSON-RPC: handle(raw) -> raw. No sockets, no FFI, so it
 * is trivially testable — this is exactly how an AI client (Claude Desktop, an
 * LLM agent) would drive the UI once it is mounted on the automation server.
 */
test('mcp initialize advertises tools and resources', function () {
    $mcp = new McpServer(fn() => [], fn() => [], null);
    $resp = json_decode($mcp->handle((string) json_encode([
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => new \stdClass(),
    ])), true);

    expect($resp['result']['protocolVersion'])->toBe('2024-11-05');
    expect($resp['result']['capabilities'])->toHaveKey('tools');
    expect($resp['result']['capabilities'])->toHaveKey('resources');
});

test('mcp tools/list exposes the three builtins', function () {
    $mcp = new McpServer(fn() => [], fn() => [], null);
    $resp = json_decode($mcp->handle((string) json_encode([
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list',
    ])), true);

    $names = array_column($resp['result']['tools'], 'name');
    expect($names)->toContain('ui_snapshot');
    expect($names)->toContain('ui_get_state');
    expect($names)->toContain('ui_drive');
});

test('mcp ui_snapshot returns the snapshot tree', function () {
    $snap = ['id' => 'root', 'role' => 'window', 'children' => []];
    $mcp = new McpServer(fn() => [$snap], fn() => [], null);
    $resp = json_decode($mcp->handle((string) json_encode([
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'ui_snapshot', 'arguments' => []],
    ])), true);

    $text = json_decode($resp['result']['content'][0]['text'], true);
    expect($text[0]['id'])->toBe('root');
});

test('mcp ui_drive delegates to the drive handler', function () {
    $called = null;
    $mcp = new McpServer(fn() => [], function ($nodeId, $payload) use (&$called) {
        $called = [$nodeId, $payload];
        return ['ok' => true];
    }, null);

    $resp = json_decode($mcp->handle((string) json_encode([
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'ui_drive', 'arguments' => ['nodeId' => 'b1', 'payload' => ['action' => 'click']]],
    ])), true);

    expect($called)->toBe(['b1', ['action' => 'click']]);
    expect($resp['result']['isError'])->toBeFalse();
});
