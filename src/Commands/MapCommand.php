<?php

namespace Tackle\Commands;

use Illuminate\Console\Command;
use Tackle\Support\AppMap;
use Tackle\Support\RouteMap;

class MapCommand extends Command
{
    protected $signature = 'tackle:map
        {model?            : A model to describe in full (short name or FQCN)}
        {--route=          : Describe a route instead — by name, URI, or Controller@method}
        {--all             : Describe every model in full}
        {--fresh           : Rebuild the map, discarding the cached one}';

    protected $description = 'Show the semantic map of the application — the models, columns, relations, scopes, observers and policies the agent sees.';

    public function handle(AppMap $map, RouteMap $routes): int
    {
        if ($this->option('fresh')) {
            $map->flush();
            $this->line('<fg=gray>Cache discarded — rebuilding.</>');
        }

        if ($route = $this->option('route')) {
            $this->line('');
            $this->line($routes->describe((string) $route));
            $this->line('');

            return self::SUCCESS;
        }

        if ($model = $this->argument('model')) {
            $this->line('');
            $this->line($map->model((string) $model));
            $this->line('');

            return self::SUCCESS;
        }

        $this->line('');
        $this->line('<fg=green;options=bold>Laravel Tackle — Application Map</>');
        $this->line('');

        if ($this->option('all')) {
            $this->line($map->all());
            $this->line('');

            return self::SUCCESS;
        }

        $index = trim($map->index());

        $this->line($index === '' ? '<fg=yellow>Nothing to map — no models found and no routes registered.</>' : $index);
        $this->line('');
        $this->line('<fg=gray>Cached at '.$map->path().' · php artisan tackle:map <model> for one model, --all for every model, --route= for a route.</>');
        $this->line('');

        return self::SUCCESS;
    }
}
