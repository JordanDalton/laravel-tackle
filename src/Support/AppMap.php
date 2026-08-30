<?php

namespace Tackle\Support;

use Illuminate\Database\Eloquent\Attributes\Scope as ScopeAttribute;
use Illuminate\Database\Eloquent\Casts\Attribute as CastAttribute;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * The semantic map of the application's Eloquent layer.
 *
 * Two tiers, because the useful thing and the affordable thing are different
 * sizes. Tier one is index(): one line naming every model and its table, cheap
 * enough to sit in the system prompt of every session — it exists to stop the
 * agent globbing app/Models and reading four files just to learn what exists.
 * Tier two is model(): the full shape of one model, pulled on demand.
 *
 * Everything here comes from the booted application rather than from source
 * files: real columns and types from the live connection, casts and relations
 * from reflection, observers from the event dispatcher, the policy from the
 * gate, the factory and its states from the factory class. A file-reading
 * agent cannot assemble this, and reading the model plus its migrations costs
 * roughly ten times the tokens for a worse and often stale answer.
 *
 * The map returns schema and metadata, never rows. QueryDatabase is the tool
 * for data and it has its own caps — keeping that line clean is what makes
 * this safe to leave enabled everywhere.
 *
 * One honest limitation: reflection reads the classes loaded into this
 * process, so when the workspace is a git worktree the map describes the
 * booted application's model code, not the worktree's copy of it. PHP cannot
 * reload a class, so nothing here can fix that — the schema half stays exact,
 * and the reflection half is as current as the process that started.
 */
class AppMap
{
    /** In-process cache, keyed by workspace: one build per process. */
    private static array $memo = [];

    /** Methods that are never scopes, accessors, or relations. */
    private const NEVER_RELATIONS = [
        'getTable', 'getKeyName', 'getRouteKey', 'getRouteKeyName', 'getMorphClass',
        'getConnectionName', 'getForeignKey', 'newQuery', 'newCollection', 'toArray',
        'jsonSerialize', 'getQueueableId', 'getAttributes', 'getOriginal', 'getChanges',
        'getDirty', 'getCasts', 'getFillable', 'getGuarded', 'getHidden', 'getVisible',
        'getIncrementing', 'getKeyType', 'getPerPage', 'getConnection', 'getDates',
        'usesTimestamps', 'getGlobalScopes', 'getObservableEvents', 'getRelations',
        'getTouchedRelations', 'refresh', 'fresh', 'delete', 'save', 'push', 'trashed',
    ];

    public function __construct(private readonly PathGuard $guard) {}

    /**
     * Tier one: the compact index injected into a session's instructions.
     *
     * One line of models, one line of route counts. Around sixty tokens, and
     * it removes the most expensive failure mode there is — an agent spending
     * four tool calls to discover the domain it was about to write code for.
     */
    public function index(): string
    {
        $lines = [];

        if ($index = $this->modelIndex()) {
            $lines[] = 'Models: '.implode(' ', array_map(
                fn ($table, $name) => $name.'('.$table.')',
                $index,
                array_keys($index),
            ));
        }

        if ($routes = $this->routeSummary()) {
            $lines[] = $routes;
        }

        return implode("\n", $lines);
    }

    /**
     * Every model's short name mapped to its table. The raw material of the
     * index, kept structured so `tackle:map` can lay it out for a human while
     * the agent gets the one-line version.
     *
     * @return array<string, string>
     */
    public function modelIndex(): array
    {
        $cached = $this->cached('index:models', function () {
            $index = [];

            foreach ($this->models() as $class) {
                try {
                    $index[class_basename($class)] = (new $class)->getTable();
                } catch (Throwable) {
                    $index[class_basename($class)] = '?';
                }
            }

            return json_encode($index);
        });

        $index = json_decode($cached, true);

        return is_array($index) ? $index : [];
    }

    /**
     * The index wrapped as a system-prompt section, or an empty string when
     * there is nothing to say or the map is switched off. Agents concatenate
     * this into their instructions.
     */
    public function indexSection(): string
    {
        if (! config('tackle.app_map.enabled', true) || ! config('tackle.app_map.index', true)) {
            return '';
        }

        $index = trim($this->index());

        if ($index === '') {
            return '';
        }

        return "\n\n".<<<MAP
        ## Application map

        {$index}

        This is an index, not the whole picture. Call DescribeModels with a model name for its real columns and types, casts, relations, scopes, observers, policy, and factory states — it reads the live schema and the booted application, so it is authoritative where a model file and its migrations are not. Prefer it over reading model files or migrations, and over guessing a column name.
        MAP;
    }

    /**
     * Tier two: the full shape of one model.
     */
    public function model(string $name): string
    {
        $models = $this->models();

        if ($models === []) {
            return 'No Eloquent models found under app/Models.';
        }

        $match = null;

        foreach ($models as $class) {
            if ($class === $name || class_basename($class) === class_basename($name)) {
                $match = $class;
                break;
            }
        }

        if ($match === null) {
            return "Model '{$name}' not found. Available: "
                .implode(', ', array_map('class_basename', $models));
        }

        return $this->cached('model:'.$match, fn () => $this->renderPlain($this->modelData($match)));
    }

    /**
     * The same picture as model(), structured rather than rendered.
     *
     * The agent gets plain text — colour codes would be pure token waste in a
     * context window — so `tackle:map` builds its own presentation from this
     * instead of re-parsing the text meant for the model.
     *
     * @return array<string, mixed>|null null when no such model exists
     */
    public function data(string $name): ?array
    {
        foreach ($this->models() as $class) {
            if ($class === $name || class_basename($class) === class_basename($name)) {
                return $this->modelData($class);
            }
        }

        return null;
    }

    /**
     * Every discovered model, described. Used by `tackle:map` — too large for
     * a tool result on any real application.
     */
    public function all(): string
    {
        $models = $this->models();

        if ($models === []) {
            return 'No Eloquent models found under app/Models.';
        }

        return implode("\n\n", array_map(fn ($class) => $this->model($class), $models));
    }

    /**
     * The application's model classes, discovered by walking app/Models (or
     * app/ on projects that never adopted the Models directory).
     *
     * @return list<string>
     */
    public function models(): array
    {
        $cached = $this->cached('models', fn () => implode("\n", $this->discoverModels()));

        return $cached === '' ? [] : explode("\n", $cached);
    }

    /**
     * Drop the map. Called when a migration runs or the agent edits a model,
     * so its own changes are visible on the next call.
     */
    public function flush(): void
    {
        unset(self::$memo[$this->guard->workspace()]);

        @unlink($this->path());
    }

    /** Forget every in-process map — used by tests and long-lived processes. */
    public static function forget(): void
    {
        self::$memo = [];
    }

    /**
     * Whether a written path invalidates the map. Models and migrations change
     * the shape; routes change the index's route counts.
     */
    public static function invalidatedBy(string $path): bool
    {
        $path = str_replace('\\', '/', $path);

        return (bool) preg_match('#(^|/)(app/Models/|database/migrations/|routes/)#', $path)
            || (bool) preg_match('#(^|/)app/.*Model\.php$#', $path);
    }

    // -----------------------------------------------------------------------
    // Describing a model
    // -----------------------------------------------------------------------

    /**
     * Everything known about one model, gathered once and rendered twice —
     * as plain text for the agent, and in colour by `tackle:map` for a human.
     *
     * @return array<string, mixed>
     */
    private function modelData(string $class): array
    {
        $base = ['name' => class_basename($class), 'class' => $class];

        try {
            /** @var Model $model */
            $model = new $class;
        } catch (Throwable $e) {
            return $base + ['error' => 'could not instantiate: '.$e->getMessage()];
        }

        $ref = new ReflectionClass($class);
        $table = $model->getTable();

        return $base + [
            'error' => null,
            'table' => $table,
            'badges' => $this->badges($class, $model),
            'columns' => $this->columnData($model, $table),
            'meta' => array_filter([
                'Casts' => collect($model->getCasts())->map(fn ($t, $k) => "{$k}:{$this->shortType($t)}")->implode('  '),
                'Fillable' => implode(', ', $model->getFillable()),
                'Guarded' => $model->getFillable() === [] ? implode(', ', $model->getGuarded()) : '',
                'Hidden' => implode(', ', $model->getHidden()),
                'Appends' => implode(', ', $this->appends($model, $ref)),
            ]),
            'relations' => $this->relations($model, $ref),
            'extras' => array_filter([
                'Scopes' => implode('  ', $this->scopes($ref)),
                'Global scopes' => implode(', ', $this->globalScopes($model)),
                'Accessors' => implode(', ', $this->accessors($ref)),
                'Factory' => $this->factory($class),
            ]),
            'note' => $this->untypedNote($ref) ?: null,
        ];
    }

    /**
     * The agent's view: no colour, no box drawing, nothing that costs tokens
     * without carrying meaning.
     *
     * @param  array<string, mixed>  $data
     */
    private function renderPlain(array $data): string
    {
        if ($data['error'] !== null) {
            return $data['name'].' — '.$data['error'];
        }

        $header = array_merge([$data['name'].' ('.$data['table'].')'], $data['badges']);

        $out = [implode(' · ', $header), $data['class'], ''];

        $out[] = $this->renderColumns($data['columns']);

        if ($data['meta'] !== []) {
            $out[] = '';

            foreach ($data['meta'] as $label => $value) {
                $out[] = sprintf('%-14s %s', $label, $value);
            }
        }

        if ($data['relations'] !== []) {
            $out[] = '';
            $out[] = 'Relations';

            foreach ($data['relations'] as $relation) {
                $out[] = '  '.$this->renderRelation($relation);
            }
        }

        if ($data['extras'] !== []) {
            $out[] = '';

            foreach ($data['extras'] as $label => $value) {
                $out[] = sprintf('%-14s %s', $label, $value);
            }
        }

        if ($data['note'] !== null) {
            $out[] = '';
            $out[] = $data['note'];
        }

        return implode("\n", $out);
    }

    /**
     * @param  array<string, mixed>  $columns
     */
    private function renderColumns(array $columns): string
    {
        if ($columns['note'] !== null) {
            return 'Columns  '.$columns['note'];
        }

        $width = $this->widths($columns['rows']);
        $lines = ['Columns'];

        foreach ($columns['rows'] as $row) {
            $lines[] = rtrim(sprintf(
                '  %-'.$width[0].'s %-'.$width[1].'s %s',
                $row['name'],
                $row['type'],
                implode(' ', $row['flags']),
            ));
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $relation
     */
    private function renderRelation(array $relation): string
    {
        if ($relation['related'] === null) {
            return rtrim(sprintf('%-20s %s', $relation['name'], $relation['type']));
        }

        return rtrim(sprintf(
            '%-20s %-16s → %-16s %s',
            $relation['name'],
            $relation['type'],
            $relation['related'],
            $relation['key'] ? '('.$relation['key'].')' : '',
        ));
    }

    /**
     * Column widths sized to the model in hand — a table of short names should
     * not be padded out to fit the widest name in some other model.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{0: int, 1: int}
     */
    private function widths(array $rows): array
    {
        $name = 4;
        $type = 4;

        foreach ($rows as $row) {
            $name = max($name, strlen((string) $row['name']));
            $type = max($type, strlen((string) $row['type']));
        }

        return [min($name, 40), min($type, 24)];
    }

    /**
     * The short facts that belong beside a model's name — the ones that change
     * how its queries behave.
     *
     * @return list<string>
     */
    private function badges(string $class, Model $model): array
    {
        $parts = [];

        if (in_array(SoftDeletes::class, class_uses_recursive($class), true)) {
            $parts[] = 'SoftDeletes';
        }

        if (! $model->usesTimestamps()) {
            $parts[] = 'no timestamps';
        }

        if ($model->getKeyType() !== 'int' || ! $model->getIncrementing()) {
            $parts[] = 'key '.$model->getKeyName().':'.$model->getKeyType()
                .($model->getIncrementing() ? '' : ' non-incrementing');
        }

        if ($connection = $model->getConnectionName()) {
            $parts[] = 'connection '.$connection;
        }

        foreach ($this->observers($class) as $observer) {
            $parts[] = 'Observer: '.class_basename($observer);
        }

        if ($policy = $this->policy($class)) {
            $parts[] = 'Policy: '.class_basename($policy);
        }

        return $parts;
    }

    /**
     * Real columns from the live connection — the half of the picture that no
     * amount of file reading produces, and the half most likely to be wrong in
     * the agent's head. When the connection is unavailable this says so
     * explicitly rather than quietly returning a partial map: half a map
     * presented as complete is worse than none, because the agent writes
     * confident code against columns it believes exist.
     *
     * @return array{rows: list<array{name: string, type: string, flags: list<string>}>, note: ?string}
     */
    private function columnData(Model $model, string $table): array
    {
        try {
            $schema = Schema::connection($model->getConnectionName());

            if (! $schema->hasTable($table)) {
                return ['rows' => [], 'note' => "unavailable — table '{$table}' does not exist on this connection (has it been migrated?)."];
            }

            $foreign = [];
            foreach ($schema->getForeignKeys($table) as $fk) {
                $local = (array) ($fk['columns'] ?? []);
                $to = (array) ($fk['foreign_columns'] ?? []);
                if ($local !== []) {
                    $foreign[$local[0]] = ($fk['foreign_table'] ?? '?').'.'.($to[0] ?? 'id');
                }
            }

            $unique = [];
            foreach ($schema->getIndexes($table) as $index) {
                $cols = (array) ($index['columns'] ?? []);
                if (($index['unique'] ?? false) && count($cols) === 1 && ! ($index['primary'] ?? false)) {
                    $unique[$cols[0]] = true;
                }
            }

            $key = $model->getKeyName();
            $rows = [];

            foreach ($schema->getColumns($table) as $column) {
                $name = (string) ($column['name'] ?? '?');

                $flags = [];
                if ($name === $key) {
                    $flags[] = 'PK';
                }
                if (isset($foreign[$name])) {
                    $flags[] = 'FK→'.$foreign[$name];
                }
                if (isset($unique[$name])) {
                    $flags[] = 'UNIQUE';
                }
                if ($column['nullable'] ?? false) {
                    $flags[] = 'NULL';
                }
                if (($column['default'] ?? null) !== null) {
                    $flags[] = 'default '.$column['default'];
                }

                $rows[] = [
                    'name' => $name,
                    'type' => (string) ($column['type'] ?? $column['type_name'] ?? '?'),
                    'flags' => $flags,
                ];
            }

            return ['rows' => $rows, 'note' => null];
        } catch (Throwable $e) {
            return ['rows' => [], 'note' => 'unavailable — the database could not be read ('.$e->getMessage().'). '
                .'Everything below comes from reflection and is unaffected; the column list is the only missing part.'];
        }
    }

    /**
     * Relations, read from declared return types.
     *
     * Return-type-first is not just a speed choice. Invoking every public
     * method to see which ones hand back a Relation is how an introspection
     * tool accidentally fires sendWelcomeEmail(). Only methods already typed
     * as returning a Relation get called, and the call is what yields the
     * related class and foreign key. Undeclared relations are reported as a
     * gap instead of being hunted for, unless the project opts in.
     *
     * @return list<array{name: string, type: string, related: ?string, key: ?string}>
     */
    private function relations(Model $model, ReflectionClass $ref): array
    {
        $relations = [];

        foreach ($this->candidateMethods($ref) as $method) {
            $name = $method->getName();
            $type = $method->getReturnType();
            $declared = $type instanceof ReflectionNamedType
                && ! $type->isBuiltin()
                && is_subclass_of($type->getName(), Relation::class);

            if (! $declared && ! config('tackle.app_map.probe_untyped_relations', false)) {
                continue;
            }

            try {
                $relation = $model->{$name}();
            } catch (Throwable) {
                if ($declared) {
                    $relations[] = ['name' => $name, 'type' => class_basename($type->getName()), 'related' => null, 'key' => null];
                }

                continue;
            }

            if (! $relation instanceof Relation) {
                continue;
            }

            $relations[] = [
                'name' => $name,
                'type' => class_basename($relation),
                'related' => class_basename($relation->getRelated()),
                'key' => method_exists($relation, 'getForeignKeyName') ? $relation->getForeignKeyName() : null,
            ];
        }

        return $relations;
    }

    /**
     * Global scopes come off the model, not out of a file — which is how the
     * agent learns that Post::count() silently excludes trashed rows.
     *
     * @return list<string>
     */
    private function globalScopes(Model $model): array
    {
        if (! method_exists($model, 'getGlobalScopes')) {
            return [];
        }

        try {
            $names = [];

            foreach ($model->getGlobalScopes() as $key => $scope) {
                $names[] = class_basename(is_object($scope) ? get_class($scope) : (string) $key);
            }

            return $names;
        } catch (Throwable) {
            return [];
        }
    }

    /** @return list<string> */
    private function scopes(ReflectionClass $ref): array
    {
        $scopes = [];

        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || ! $this->declaredOn($ref, $method)) {
                continue;
            }

            $name = $method->getName();
            $attributed = class_exists(ScopeAttribute::class) && $method->getAttributes(ScopeAttribute::class) !== [];

            if (str_starts_with($name, 'scope') && strlen($name) > 5) {
                $name = lcfirst(substr($name, 5));
                $args = array_slice($this->parameters($method), 1);
            } elseif ($attributed) {
                $args = array_slice($this->parameters($method), 1);
            } else {
                continue;
            }

            $scopes[] = $name.'('.implode(', ', $args).')';
        }

        return $scopes;
    }

    /** @return list<string> */
    private function accessors(ReflectionClass $ref): array
    {
        $accessors = [];

        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (! $this->declaredOn($ref, $method)) {
                continue;
            }

            $name = $method->getName();
            $type = $method->getReturnType();

            if ($type instanceof ReflectionNamedType && $type->getName() === CastAttribute::class) {
                $accessors[] = $name;
            } elseif (preg_match('/^get(.+)Attribute$/', $name, $m)) {
                $accessors[] = lcfirst($m[1]);
            }
        }

        return array_values(array_unique($accessors));
    }

    /**
     * Observers are registered on the event dispatcher, not stored on the
     * model — so reading them here catches observers a package registered,
     * which grepping the application never would.
     *
     * @return list<string>
     */
    private function observers(string $class): array
    {
        try {
            $raw = Event::getRawListeners();
        } catch (Throwable) {
            return [];
        }

        $found = [];

        foreach ($raw as $event => $handlers) {
            if (! is_string($event) || ! str_starts_with($event, 'eloquent.') || ! str_ends_with($event, ': '.$class)) {
                continue;
            }

            foreach ((array) $handlers as $handler) {
                $name = match (true) {
                    is_string($handler) => explode('@', $handler)[0],
                    is_array($handler) && isset($handler[0]) => is_object($handler[0]) ? get_class($handler[0]) : (string) $handler[0],
                    is_object($handler) && ! $handler instanceof \Closure => get_class($handler),
                    default => null,
                };

                if ($name !== null && $name !== $class && ! in_array($name, $found, true)) {
                    $found[] = $name;
                }
            }
        }

        return $found;
    }

    private function policy(string $class): ?string
    {
        try {
            $policy = Gate::getPolicyFor($class);
        } catch (Throwable) {
            return null;
        }

        return $policy ? (is_object($policy) ? get_class($policy) : (string) $policy) : null;
    }

    /**
     * The factory and its states — a direct gift to ai:test, which otherwise
     * guesses at state names and column values and fails on the first run.
     */
    private function factory(string $class): string
    {
        try {
            if (! method_exists($class, 'factory') || ! class_exists(Factory::class)) {
                return '';
            }

            $factory = Factory::resolveFactoryName($class);

            if (! is_string($factory) || ! class_exists($factory)) {
                return '';
            }

            $states = [];
            $ref = new ReflectionClass($factory);

            foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $factory) {
                    continue;
                }

                if (in_array($method->getName(), ['definition', 'configure', 'modelName', 'newModel', 'withFaker'], true)) {
                    continue;
                }

                $states[] = $method->getName();
            }

            return class_basename($factory).($states !== [] ? '  states: '.implode(', ', $states) : '');
        } catch (Throwable) {
            return '';
        }
    }

    /** @return list<string> */
    private function appends(Model $model, ReflectionClass $ref): array
    {
        try {
            $property = $ref->getProperty('appends');
            $property->setAccessible(true);

            return array_values((array) $property->getValue($model));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Honest reporting of what reflection could not see. A model without
     * return types has relations this map will not list, and the agent needs
     * to know that its picture is partial rather than empty.
     */
    private function untypedNote(ReflectionClass $ref): string
    {
        if (config('tackle.app_map.probe_untyped_relations', false)) {
            return '';
        }

        $untyped = 0;

        foreach ($this->candidateMethods($ref) as $method) {
            if ($method->getReturnType() === null) {
                $untyped++;
            }
        }

        return $untyped === 0 ? '' : "Note: {$untyped} public method(s) declare no return type; any relations among them are not listed above. "
            .'Read the model file if you need them, or set tackle.app_map.probe_untyped_relations to detect them by invocation.';
    }

    /**
     * Zero-argument public methods declared on the model itself — the only
     * ones that could be relations.
     *
     * @return list<ReflectionMethod>
     */
    private function candidateMethods(ReflectionClass $ref): array
    {
        $methods = [];

        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic()
                || $method->getNumberOfParameters() > 0
                || ! $this->declaredOn($ref, $method)
                || str_starts_with($method->getName(), '__')
                || str_starts_with($method->getName(), 'scope')
                || in_array($method->getName(), self::NEVER_RELATIONS, true)) {
                continue;
            }

            $methods[] = $method;
        }

        return $methods;
    }

    /**
     * Whether the method was written on the model itself.
     *
     * Reflection reports a trait's methods as declared by the composing class,
     * so SoftDeletes alone would contribute a dozen methods to every count and
     * turn the untyped-methods note into noise. Comparing files separates what
     * the developer wrote from what a trait supplied.
     */
    private function declaredOn(ReflectionClass $ref, ReflectionMethod $method): bool
    {
        return $method->getDeclaringClass()->getName() === $ref->getName()
            && $method->getFileName() === $ref->getFileName();
    }

    /** @return list<string> */
    private function parameters(ReflectionMethod $method): array
    {
        return array_map(
            fn ($p) => '$'.$p->getName(),
            $method->getParameters(),
        );
    }

    private function shortType(mixed $cast): string
    {
        $cast = (string) $cast;

        return str_contains($cast, '\\') ? class_basename(explode(':', $cast)[0]) : $cast;
    }

    // -----------------------------------------------------------------------
    // Discovery, routes, caching
    // -----------------------------------------------------------------------

    /** @return list<string> */
    private function discoverModels(): array
    {
        $workspace = rtrim($this->guard->workspace(), '/');

        $roots = [$workspace.'/app/Models' => 'App\\Models\\'];

        if (! is_dir($workspace.'/app/Models')) {
            $roots = [$workspace.'/app' => 'App\\'];
        }

        $models = [];

        foreach ($roots as $dir => $namespace) {
            foreach ($this->phpFiles($dir) as $file) {
                $relative = trim(str_replace([$dir, '.php'], '', $file), '/');
                $class = $namespace.str_replace('/', '\\', $relative);

                try {
                    if (class_exists($class)
                        && is_subclass_of($class, Model::class)
                        && ! (new ReflectionClass($class))->isAbstract()) {
                        $models[] = $class;
                    }
                } catch (Throwable) {
                    // A file that will not load tells us nothing; skip it.
                }
            }
        }

        sort($models);

        return array_values(array_unique($models));
    }

    private function routeSummary(): string
    {
        ['routes' => $routes, 'controllers' => $controllers] = $this->routeCounts();

        if ($routes === 0) {
            return '';
        }

        $line = "Routes: {$routes} across {$controllers} controllers. Use ListRoutes to find one, DescribeRoute for its middleware, validation and authorization.";

        $entry = $this->entryPoint();

        return $entry === '' ? $line : $line."\n".$entry;
    }

    /**
     * What the site's root actually renders.
     *
     * The line above is a pointer, and dereferencing it costs tool calls: a
     * run watched in production spent three steps on ListRoutes and
     * `route:list` working out where "the homepage" lived, then edited the
     * wrong file anyway. The router already knows, and saying so costs about
     * twenty tokens.
     *
     * Deliberately only the root. Listing every route would grow the per-step
     * floor for every run, including the ones that never touch a route; the
     * entry point is the one a task is most often described relative to.
     */
    private function entryPoint(): string
    {
        try {
            foreach (app('router')->getRoutes() as $route) {
                if (trim($route->uri(), '/') !== '' || ! in_array('GET', $route->methods(), true)) {
                    continue;
                }

                $name = $route->getName();
                $suffix = $name ? " [name: {$name}]" : '';

                // Inertia's route macro stashes the page component in the
                // route defaults; Laravel's Route::view does the same with a
                // view name. Either is the answer the agent is looking for.
                $defaults = $route->defaults ?? [];

                if (is_string($component = $defaults['component'] ?? null) && $component !== '') {
                    $file = 'resources/js/pages/'.$component.'.vue';

                    return 'Entry: GET / renders the Inertia page '.$component
                        .(is_file($this->guard->workspace().'/'.$file) ? " ({$file})" : '').$suffix;
                }

                if (is_string($view = $defaults['view'] ?? null) && $view !== '') {
                    return "Entry: GET / renders the view {$view}{$suffix}";
                }

                $action = $route->getActionName();

                return $action === 'Closure'
                    ? "Entry: GET / is a closure in the route files{$suffix}"
                    : "Entry: GET / is handled by {$action}{$suffix}";
            }
        } catch (Throwable) {
            // An app map is a convenience; never let it break a run.
        }

        return '';
    }

    /**
     * How many routes, and how many controllers behind them.
     *
     * @return array{routes: int, controllers: int}
     */
    public function routeCounts(): array
    {
        try {
            $routes = app('router')->getRoutes();
            $controllers = [];

            foreach ($routes as $route) {
                $action = $route->getActionName();

                if ($action !== 'Closure' && str_contains($action, '@')) {
                    $controllers[explode('@', $action)[0]] = true;
                }
            }

            return ['routes' => count($routes), 'controllers' => count($controllers)];
        } catch (Throwable) {
            return ['routes' => 0, 'controllers' => 0];
        }
    }

    /**
     * A key the map can be cached against: every model, migration, and route
     * file with its mtime. Any change to the shape of the application changes
     * the fingerprint, and a stale map is discarded on the next call.
     */
    public function fingerprint(): string
    {
        $workspace = rtrim($this->guard->workspace(), '/');
        $parts = [$workspace];

        foreach (['app/Models', 'database/migrations', 'routes'] as $relative) {
            foreach ($this->phpFiles($workspace.'/'.$relative) as $file) {
                $parts[] = $file.':'.(@filemtime($file) ?: 0);
            }
        }

        return md5(implode('|', $parts));
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $files = [];

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }

                // A pathological directory should degrade the map, not hang it.
                if (count($files) >= 2000) {
                    break;
                }
            }
        } catch (Throwable) {
            return [];
        }

        sort($files);

        return $files;
    }

    /**
     * Memoise in-process and persist to disk, both keyed on the fingerprint.
     * The in-process half is what matters during a session — instructions()
     * is rebuilt on every step — and the disk half saves the first build for
     * the next command, the healer, or CI.
     */
    private function cached(string $key, callable $build): string
    {
        $workspace = $this->guard->workspace();
        $fingerprint = $this->fingerprint();

        $payload = self::$memo[$workspace] ?? null;

        if ($payload === null || ($payload['fingerprint'] ?? null) !== $fingerprint) {
            $payload = $this->read();

            if (($payload['fingerprint'] ?? null) !== $fingerprint) {
                $payload = ['fingerprint' => $fingerprint, 'entries' => []];
            }
        }

        if (! array_key_exists($key, $payload['entries'])) {
            try {
                $payload['entries'][$key] = (string) $build();
            } catch (Throwable $e) {
                return 'The application map could not be built: '.$e->getMessage();
            }

            $this->write($payload);
        }

        self::$memo[$workspace] = $payload;

        return $payload['entries'][$key];
    }

    private function read(): array
    {
        if (! config('tackle.app_map.cache', true)) {
            return [];
        }

        $raw = @file_get_contents($this->path());

        if ($raw === false) {
            return [];
        }

        $data = json_decode($raw, true);

        return is_array($data) && ($data['workspace'] ?? null) === $this->guard->workspace()
            ? ['fingerprint' => (string) ($data['fingerprint'] ?? ''), 'entries' => (array) ($data['entries'] ?? [])]
            : [];
    }

    private function write(array $payload): void
    {
        if (! config('tackle.app_map.cache', true)) {
            return;
        }

        try {
            $dir = dirname($this->path());
            @mkdir($dir, 0755, true);

            // Same reason as the session store: a written file inside the app
            // that git does not ignore is a content source to Tailwind's Vite
            // plugin, which full-reloads the browser every time we touch it.
            if (! is_file($dir.'/.gitignore')) {
                @file_put_contents($dir.'/.gitignore', "*\n");
            }

            @file_put_contents($this->path(), json_encode([
                'workspace' => $this->guard->workspace(),
                'fingerprint' => $payload['fingerprint'],
                'entries' => $payload['entries'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } catch (Throwable) {
            // The cache is an optimisation; never let it break a session.
        }
    }

    public function path(): string
    {
        return storage_path('tackle/map.json');
    }
}
