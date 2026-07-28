<?php

declare(strict_types=1);

use Yangweijie\Ui3\Automation\Automation;
use Yangweijie\Ui3\Backends\Canvas;
use Yangweijie\Ui3\System\AutomationServer;

/**
 * AutomationServer is pure PHP and its handleRequest() returns a JSON BODY (the
 * HTTP envelope is only added when poll() writes to a socket). These tests drive
 * it directly with a headless app instance, proving the REST + MCP surface works
 * end-to-end WITHOUT the OS key/click path — the AI-friendly drive layer the
 * real window will be verified through.
 */
test('server /snapshot exposes the widget tree', function () {
    $auto = (new Automation(editApp(), new Canvas(headless: true)))->start();
    $server = (new AutomationServer($auto, fn() => $auto->model(), true))->start(0);

    $resp = json_decode($server->handleRequest('GET', '/snapshot', ''), true);

    $ids = [];
    foreach ($resp['windows'] as $w) {
        foreach ($w['widgets'] ?? [] as $widget) {
            $ids[] = $widget['id'] ?? null;
        }
    }

    expect($ids)->toContain('v-input');
});

test('server /drive sets a field value and updates the model', function () {
    $auto = (new Automation(editApp(), new Canvas(headless: true)))->start();
    $server = (new AutomationServer($auto, fn() => $auto->model(), true))->start(0);

    $resp = json_decode(
        $server->handleRequest('POST', '/drive', (string) json_encode([
            'nodeId' => 'v-input',
            'action' => 'set_value',
            'value' => 'hi',
        ])),
        true,
    );

    expect($resp['ok'])->toBeTrue();
    expect($auto->model()['v'])->toBe('hi');
});

test('server /mcp ui_drive sets a field value without key events', function () {
    $auto = (new Automation(editApp(), new Canvas(headless: true)))->start();
    $server = (new AutomationServer($auto, fn() => $auto->model(), true))->start(0);

    $body = (string) json_encode([
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'ui_drive', 'arguments' => ['nodeId' => 'v-input', 'payload' => ['action' => 'set_value', 'value' => 'hi']]],
    ]);
    $resp = json_decode($server->handleRequest('POST', '/mcp', $body), true);

    $result = json_decode($resp['result']['content'][0]['text'], true);
    expect($result['ok'])->toBeTrue();
    expect($auto->model()['v'])->toBe('hi');
});
