<?php

namespace Tackle\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Tools\Request;
use Tackle\Support\PathGuard;

class ListRoutes extends AbstractTool
{
    public function __construct(private PathGuard $guard) {}

    public function description(): string
    {
        return 'List the application\'s registered routes. Returns method, URI, name, and action. Use to understand routing before editing controllers or middleware.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'filter' => $schema->string()
                ->description('Optional string to filter routes by URI, name, or action.'),
            'method' => $schema->string()
                ->description('Optional HTTP method to filter by (GET, POST, PUT, PATCH, DELETE).'),
        ];
    }

    public function handle(Request $request): string
    {
        $workspace = $this->guard->workspace();

        $cmd = ['php', 'artisan', 'route:list', '--json'];

        if ($filter = $request->string('filter', '')) {
            $cmd = array_merge($cmd, ['--filter', $filter]);
        }

        if ($method = $request->string('method', '')) {
            $cmd = array_merge($cmd, ['--method', strtoupper($method)]);
        }

        $result = Process::path($workspace)->timeout(30)->run($cmd);

        if (! $result->successful()) {
            return 'Could not retrieve routes: ' . trim($result->errorOutput());
        }

        $routes = json_decode(trim($result->output()), true);

        if (! is_array($routes) || empty($routes)) {
            return 'No routes found.';
        }

        $lines = array_map(fn ($r) => sprintf(
            '%-8s %-45s %-30s %s',
            implode('|', (array) ($r['method'] ?? '')),
            $r['uri']    ?? '',
            $r['name']   ?? '',
            $r['action'] ?? '',
        ), $routes);

        return sprintf("%-8s %-45s %-30s %s\n", 'METHOD', 'URI', 'NAME', 'ACTION')
            . str_repeat('-', 120) . "\n"
            . implode("\n", $lines);
    }
}
