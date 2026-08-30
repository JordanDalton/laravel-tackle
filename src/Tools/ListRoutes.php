<?php

namespace Tackle\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Tools\Request;
use Tackle\Support\PathGuard;
use Tackle\Support\ToolOutput;

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
        $filter = strtolower(trim($this->arg($request, 'filter')));
        $method = strtoupper(trim($this->arg($request, 'method')));

        // A fresh process, on purpose. Reading the booted router in-process
        // was tried and reported "no routes matched" for a route file the
        // agent had written two steps earlier — the router had booted before
        // the file existed. `route:list` boots the app as it is on disk now.
        //
        // No --filter: that option does not exist (route:list has --path,
        // --name and --action, which AND together). The whole list is
        // fetched and one filter is applied here across uri, name and action.
        $result = Process::path($this->guard->workspace())
            ->timeout(30)
            ->run(['php', 'artisan', 'route:list', '--json']);

        if (! $result->successful()) {
            // Artisan prints its errors on stdout. Reporting stderr alone
            // produced "Could not retrieve routes:" and nothing after it.
            return 'Could not retrieve routes: '.trim($result->errorOutput()."\n".$result->output());
        }

        $routes = json_decode(trim($result->output()), true);

        if (! is_array($routes)) {
            return 'Could not retrieve routes: unexpected output from route:list.';
        }

        $lines = [];

        foreach ($routes as $r) {
            $methods = array_values(array_diff(explode('|', (string) ($r['method'] ?? '')), ['HEAD']));

            if ($method !== '' && ! in_array($method, $methods, true)) {
                continue;
            }

            $uri = (string) ($r['uri'] ?? '');
            $name = (string) ($r['name'] ?? '');
            $action = (string) ($r['action'] ?? '');

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

        return ToolOutput::cap(implode("\n", $lines), 'ListRoutes');
    }
}
