<?php

namespace Tackle\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Terminal;
use Tackle\Support\AppMap;
use Tackle\Support\RouteMap;

/**
 * The application map, rendered for a human.
 *
 * The agent gets the map as plain text — colour codes in a context window are
 * tokens spent on nothing — so this command builds its own presentation from
 * AppMap::data() rather than re-parsing the text meant for the model. Same
 * facts, laid out to be scanned rather than to be cheap.
 */
class MapCommand extends Command
{
    protected $signature = 'tackle:map
        {model?            : A model to describe in full (short name or FQCN)}
        {--route=          : Describe a route instead — by name, URI, or Controller@method}
        {--all             : Describe every model in full}
        {--plain           : Print exactly what the agent sees, uncoloured}
        {--fresh           : Rebuild the map, discarding the cached one}';

    protected $description = 'Show the semantic map of the application — the models, columns, relations, scopes, observers and policies the agent sees.';

    public function handle(AppMap $map, RouteMap $routes): int
    {
        if ($this->option('fresh')) {
            $map->flush();
        }

        if ($route = $this->option('route')) {
            $this->line('');
            $this->line($routes->describe((string) $route));
            $this->line('');

            return self::SUCCESS;
        }

        if ($model = $this->argument('model')) {
            return $this->describeModels($map, [(string) $model]);
        }

        if ($this->option('all')) {
            $names = array_keys($map->modelIndex());

            if (! $this->option('plain') && $names !== []) {
                $this->newLine();
                $this->line('  <fg=green;options=bold>Laravel Tackle — Application Map</>  <fg=gray>'.count($names).' models</>');
            }

            return $this->describeModels($map, $names);
        }

        return $this->showIndex($map);
    }

    /**
     * @param  list<string>  $names
     */
    private function describeModels(AppMap $map, array $names): int
    {
        if ($names === []) {
            $this->newLine();
            $this->warn('No Eloquent models found under app/Models.');
            $this->newLine();

            return self::SUCCESS;
        }

        if ($this->option('plain')) {
            $this->line('');
            $this->line(implode("\n\n", array_map(fn ($name) => $map->model($name), $names)));
            $this->line('');

            return self::SUCCESS;
        }

        foreach ($names as $name) {
            $data = $map->data($name);

            if ($data === null) {
                $this->newLine();
                $this->error("Model '{$name}' not found.");
                $this->line('  <fg=gray>Available: '.implode(', ', array_keys($map->modelIndex())).'</>');
                $this->newLine();

                return self::FAILURE;
            }

            $this->renderModel($data);
        }

        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function renderModel(array $data): void
    {
        $this->newLine();
        $this->rule();

        if ($data['error'] !== null) {
            $this->line('  <fg=red;options=bold>'.$this->safe($data['name']).'</>  <fg=red>'.$this->safe($data['error']).'</>');

            return;
        }

        $badges = $data['badges'] === []
            ? ''
            : '   <fg=yellow>'.$this->safe(implode(' · ', $data['badges'])).'</>';

        $this->line('  <fg=green;options=bold>'.$this->safe($data['name']).'</>'
            .'  <fg=cyan>'.$this->safe($data['table']).'</>'.$badges);
        $this->line('  <fg=gray>'.$this->safe($data['class']).'</>');

        $this->renderColumns($data['columns']);
        $this->renderRelations($data['relations']);

        $labels = $data['meta'] + $data['extras'];

        if ($labels !== []) {
            $this->newLine();

            $width = max(array_map('strlen', array_keys($labels)));

            foreach ($labels as $label => $value) {
                $this->line(rtrim(sprintf(
                    '  <fg=yellow>%-'.$width.'s</>  %s',
                    $this->safe($label),
                    $this->safe((string) $value),
                )));
            }
        }

        if ($data['note'] !== null) {
            $this->newLine();
            $this->line('  <fg=gray>'.$this->safe($data['note']).'</>');
        }
    }

    /**
     * @param  array<string, mixed>  $columns
     */
    private function renderColumns(array $columns): void
    {
        $this->newLine();

        if ($columns['note'] !== null) {
            $this->line('  <options=bold>COLUMNS</>  <fg=yellow>'.$this->safe($columns['note']).'</>');

            return;
        }

        $this->line('  <options=bold>COLUMNS</>');

        $name = max(4, ...array_map(fn ($row) => strlen($row['name']), $columns['rows']));
        $type = max(4, ...array_map(fn ($row) => strlen($row['type']), $columns['rows']));

        foreach ($columns['rows'] as $row) {
            $flags = implode(' ', array_map(fn ($flag) => $this->flag($flag), $row['flags']));

            // Pad the type only when something follows it — otherwise the row
            // ends in padding hidden inside a colour tag, which rtrim cannot
            // see and anyone pasting the output inherits.
            $this->line(sprintf(
                '    %-'.$name.'s  <fg=gray>%s</>%s',
                $this->safe($row['name']),
                $flags === '' ? $this->safe($row['type']) : sprintf('%-'.$type.'s', $this->safe($row['type'])),
                $flags === '' ? '' : '  '.$flags,
            ));
        }
    }

    /**
     * @param  list<array<string, mixed>>  $relations
     */
    private function renderRelations(array $relations): void
    {
        if ($relations === []) {
            return;
        }

        $this->newLine();
        $this->line('  <options=bold>RELATIONS</>');

        $name = max(4, ...array_map(fn ($r) => strlen($r['name']), $relations));
        $type = max(4, ...array_map(fn ($r) => strlen($r['type']), $relations));
        $related = max(4, ...array_map(fn ($r) => strlen((string) $r['related']), $relations));

        foreach ($relations as $relation) {
            $key = $relation['key'] ? '  <fg=gray>('.$this->safe($relation['key']).')</>' : '';

            $target = $relation['related'] === null
                ? '<fg=gray>(could not resolve)</>'
                : sprintf(
                    '<fg=gray>→</>  <fg=green>%s</>%s',
                    $key === '' ? $this->safe($relation['related']) : sprintf('%-'.$related.'s', $this->safe($relation['related'])),
                    $key,
                );

            $this->line(sprintf(
                '    %-'.$name.'s  <fg=cyan>%-'.$type.'s</>  %s',
                $this->safe($relation['name']),
                $this->safe($relation['type']),
                $target,
            ));
        }
    }

    private function showIndex(AppMap $map): int
    {
        $index = $map->modelIndex();

        $this->newLine();
        $this->line('  <fg=green;options=bold>Laravel Tackle — Application Map</>');
        $this->rule();

        if ($index === []) {
            $this->line('  <fg=yellow>No Eloquent models found under app/Models.</>');
        } else {
            $width = max(array_map('strlen', array_keys($index)));

            $this->line(sprintf('  <fg=gray>%-'.$width.'s  %s</>', 'MODEL', 'TABLE'));

            foreach ($index as $name => $table) {
                $this->line(sprintf(
                    '  <fg=green>%-'.$width.'s</>  <fg=cyan>%s</>',
                    $this->safe($name),
                    $this->safe($table),
                ));
            }
        }

        ['routes' => $routes, 'controllers' => $controllers] = $map->routeCounts();

        if ($routes > 0) {
            $this->newLine();
            $this->line("  <fg=gray>{$routes} routes across {$controllers} controllers</>");
        }

        $this->newLine();
        $this->line('  <fg=gray>Cached at '.$this->safe($map->path()).'</>');
        $this->line('  <fg=gray>'.$this->safe('tackle:map <model> · --all · --route=<name> · --plain (what the agent sees) · --fresh').'</>');
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * Colour a column flag by what it means: keys and uniqueness are structure,
     * nullability and defaults are detail.
     */
    private function flag(string $flag): string
    {
        $colour = match (true) {
            $flag === 'PK', $flag === 'UNIQUE' => 'cyan',
            str_starts_with($flag, 'FK') => 'magenta',
            default => 'gray',
        };

        return '<fg='.$colour.'>'.$this->safe($flag).'</>';
    }

    private function rule(): void
    {
        $width = min((new Terminal)->getWidth() - 2, 100);

        $this->line('  <fg=gray>'.str_repeat('─', max($width, 20)).'</>');
    }

    /**
     * Column defaults and cast names are application data, not markup — a
     * default of `<empty>` should print, not blow up the formatter.
     */
    private function safe(string $value): string
    {
        return OutputFormatter::escape($value);
    }
}
