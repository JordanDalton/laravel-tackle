<?php

namespace Tackle\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Tackle\Support\PathGuard;
use Tackle\Support\ToolOutput;
use Throwable;

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
        $filter = strtolower(trim($request->string('filter', '')));
        $method = strtoupper(trim($request->string('method', '')));

        // Read the booted router rather than shelling out to `route:list`.
        // The subprocess version passed a `--filter` option that command does
        // not have, so every filtered call failed — and artisan prints that
        // error on stdout, which the tool did not report, so it failed blank.
        // The app map already reads routes this way; it needs no APP_KEY, no
        // subprocess, and no parsing of its own output.
        try {
            $routes = app('router')->getRoutes();
        } catch (Throwable $e) {
            return 'Could not retrieve routes: '.$e->getMessage();
        }

        $lines = [];

        foreach ($routes as $route) {
            $methods = array_values(array_diff($route->methods(), ['HEAD']));

            if ($method !== '' && ! in_array($method, $methods, true)) {
                continue;
            }

            $uri = $route->uri();
            $name = (string) $route->getName();
            $action = $route->getActionName();

            if ($filter !== '' && ! str_contains(strtolower($uri.' '.$name.' '.$action), $filter)) {
                continue;
            }

            $lines[] = sprintf('%-8s %-45s %-30s %s', implode('|', $methods), $uri, $name, $action);
        }

        if ($lines === []) {
            return $filter !== '' || $method !== ''
                ? 'No routes matched'.($filter !== '' ? " '{$filter}'" : '').($method !== '' ? " [{$method}]" : '').'.'
                : 'No routes found.';
        }

        return ToolOutput::cap(implode("\n", $lines));
    }
}
