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
        return $this->cached('index', function () {
            $models = $this->models();

            $lines = [];

            if ($models !== []) {
                $lines[] = 'Models: '.implode(' ', array_map(function (string $class) {
                    try {
                        return class_basename($class).'('.(new $class)->getTable().')';
                    } catch (Throwable) {
                        return class_basename($class);
                    }
                }, $models));
            }

            if ($routes = $this->routeSummary()) {
                $lines[] = $routes;
            }

            return implode("\n", $lines);
        });
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

        return $this->cached('model:'.$match, fn () => $this->describe($match));
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

    private function describe(string $class): string
    {
        try {
            /** @var Model $model */
            $model = new $class;
        } catch (Throwable $e) {
            return class_basename($class).' — could not instantiate: '.$e->getMessage();
        }

        $ref = new ReflectionClass($class);
        $table = $model->getTable();

        $out = [$this->header($class, $model, $ref, $table)];

        $out[] = '';
        $out[] = $this->columns($model, $table);

        $meta = array_filter([
            $this->line('Casts', collect($model->getCasts())->map(fn ($t, $k) => "{$k}:{$this->shortType($t)}")->implode('  ')),
            $this->line('Fillable', implode(', ', $model->getFillable())),
            $this->line('Guarded', $model->getFillable() === [] ? implode(', ', $model->getGuarded()) : ''),
            $this->line('Hidden', implode(', ', $model->getHidden())),
            $this->line('Appends', implode(', ', $this->appends($model, $ref))),
        ]);

        if ($meta !== []) {
            $out[] = '';
            $out = array_merge($out, $meta);
        }

        if ($relations = $this->relations($model, $ref)) {
            $out[] = '';
            $out[] = 'Relations';
            foreach ($relations as $relation) {
                $out[] = '  '.$relation;
            }
        }

        $extras = array_filter([
            $this->line('Scopes', implode('  ', $this->scopes($ref))),
            $this->line('Global scopes', implode(', ', $this->globalScopes($model))),
            $this->line('Accessors', implode(', ', $this->accessors($ref))),
            $this->line('Factory', $this->factory($class)),
        ]);

        if ($extras !== []) {
            $out[] = '';
            $out = array_merge($out, $extras);
        }

        if ($note = $this->untypedNote($ref)) {
            $out[] = '';
            $out[] = $note;
        }

        return implode("\n", $out);
    }

    private function header(string $class, Model $model, ReflectionClass $ref, string $table): string
    {
        $parts = [class_basename($class).' ('.$table.')'];

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

        return implode(' · ', $parts)."\n".$ref->getName();
    }

    /**
     * Real columns from the live connection — the half of the picture that no
     * amount of file reading produces, and the half most likely to be wrong in
     * the agent's head. When the connection is unavailable this says so
     * explicitly rather than quietly returning a partial map: half a map
     * presented as complete is worse than none, because the agent writes
     * confident code against columns it believes exist.
     */
    private function columns(Model $model, string $table): string
    {
        try {
            $schema = Schema::connection($model->getConnectionName());

            if (! $schema->hasTable($table)) {
                return "Columns  unavailable — table '{$table}' does not exist on this connection (has it been migrated?).";
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
            $lines = ['Columns'];

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

                $lines[] = sprintf(
                    '  %-24s %-16s %s',
                    $name,
                    (string) ($column['type'] ?? $column['type_name'] ?? '?'),
                    implode(' ', $flags),
                );
            }

            return implode("\n", array_map('rtrim', $lines));
        } catch (Throwable $e) {
            return 'Columns  unavailable — the database could not be read ('.$e->getMessage().'). '
                .'Everything below comes from reflection and is unaffected; the column list is the only missing part.';
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
     * @return list<string>
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
                    $relations[] = rtrim(sprintf('%-20s %s', $name, class_basename($type->getName())));
                }

                continue;
            }

            if (! $relation instanceof Relation) {
                continue;
            }

            $key = method_exists($relation, 'getForeignKeyName') ? $relation->getForeignKeyName() : null;

            $relations[] = trim(sprintf(
                '%-20s %-16s → %-16s %s',
                $name,
                class_basename($relation),
                class_basename($relation->getRelated()),
                $key ? '('.$key.')' : '',
            ));
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

    private function line(string $label, string $value): string
    {
        return $value === '' ? '' : sprintf('%-14s %s', $label, $value);
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
        try {
            $routes = app('router')->getRoutes();
            $total = count($routes);

            if ($total === 0) {
                return '';
            }

            $controllers = [];

            foreach ($routes as $route) {
                $action = $route->getActionName();

                if ($action !== 'Closure' && str_contains($action, '@')) {
                    $controllers[explode('@', $action)[0]] = true;
                }
            }

            return "Routes: {$total} across ".count($controllers).' controllers. Use ListRoutes to find one, DescribeRoute for its middleware, validation and authorization.';
        } catch (Throwable) {
            return '';
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
