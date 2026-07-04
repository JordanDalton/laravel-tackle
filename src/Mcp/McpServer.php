<?php

namespace Tackle\Mcp;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\ObjectType;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Throwable;

/**
 * A minimal Model Context Protocol server (stdio transport, JSON-RPC 2.0)
 * that exposes Tackle tools to external MCP clients such as Claude Code,
 * Cursor, or Zed. Transport-agnostic: run() drives a stream pair, while
 * handleMessage() processes a single decoded message (used by tests).
 */
class McpServer
{
    public const PROTOCOL_VERSION = '2025-06-18';

    /** @var array<string, Tool> */
    private array $tools = [];

    /**
     * @param  iterable<Tool>  $tools
     */
    public function __construct(
        iterable $tools,
        private readonly string $name = 'laravel-tackle',
        private readonly string $version = 'dev',
    ) {
        foreach ($tools as $tool) {
            $this->tools[$this->toolName($tool)] = $tool;
        }
    }

    /**
     * Read newline-delimited JSON-RPC messages from $input until EOF,
     * writing responses to $output. Notifications produce no response.
     *
     * @param  resource  $input
     * @param  resource  $output
     */
    public function run($input, $output): void
    {
        while (($line = fgets($input)) !== false) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $message = json_decode($line, true);

            if (! is_array($message)) {
                fwrite($output, json_encode([
                    'jsonrpc' => '2.0',
                    'id' => null,
                    'error' => ['code' => -32700, 'message' => 'Parse error'],
                ])."\n");

                continue;
            }

            $response = $this->handleMessage($message);

            if ($response !== null) {
                fwrite($output, json_encode($response)."\n");
            }
        }
    }

    /**
     * Handle a single JSON-RPC message. Returns the response payload, or
     * null for notifications (messages without an id).
     */
    public function handleMessage(array $message): ?array
    {
        $method = $message['method'] ?? '';
        $params = $message['params'] ?? [];
        $id = $message['id'] ?? null;

        // Notifications (initialized, cancelled, etc.) expect no response.
        if (! array_key_exists('id', $message)) {
            return null;
        }

        $result = match ($method) {
            'initialize' => $this->initialize($params),
            'ping' => (object) [],
            'tools/list' => $this->listTools(),
            'tools/call' => $this->callTool($params),
            default => null,
        };

        if ($result === null) {
            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'error' => ['code' => -32601, 'message' => "Method not found: {$method}"],
            ];
        }

        if ($result instanceof McpError) {
            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'error' => ['code' => $result->code, 'message' => $result->message],
            ];
        }

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];
    }

    private function initialize(array $params): array
    {
        return [
            'protocolVersion' => is_string($params['protocolVersion'] ?? null)
                ? $params['protocolVersion']
                : self::PROTOCOL_VERSION,
            'capabilities' => ['tools' => (object) []],
            'serverInfo' => ['name' => $this->name, 'version' => $this->version],
        ];
    }

    private function listTools(): array
    {
        $tools = [];

        foreach ($this->tools as $name => $tool) {
            $tools[] = [
                'name' => $name,
                'description' => (string) $tool->description(),
                'inputSchema' => $this->inputSchema($tool),
            ];
        }

        return ['tools' => $tools];
    }

    private function callTool(array $params): array|McpError
    {
        $name = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        $tool = $this->tools[$name] ?? null;

        if ($tool === null) {
            return new McpError(-32602, "Unknown tool: {$name}");
        }

        if (! is_array($arguments)) {
            return new McpError(-32602, 'Tool arguments must be an object.');
        }

        try {
            $text = (string) $tool->handle(new Request($arguments));
        } catch (Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Tool error: '.$e->getMessage()]],
                'isError' => true,
            ];
        }

        return [
            'content' => [['type' => 'text', 'text' => $text]],
            'isError' => false,
        ];
    }

    /**
     * Convert a tool's fluent schema definition into a JSON Schema object
     * as required by the MCP tools/list response.
     */
    private function inputSchema(Tool $tool): array
    {
        $properties = $tool->schema(new JsonSchemaTypeFactory);

        $schema = (new ObjectType($properties))->toArray();

        // MCP clients expect at least {"type": "object"}.
        return $schema ?: ['type' => 'object'];
    }

    private function toolName(Tool $tool): string
    {
        return method_exists($tool, 'name')
            ? (string) $tool->name()
            : class_basename($tool);
    }
}
