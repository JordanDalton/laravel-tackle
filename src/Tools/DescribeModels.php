<?php

namespace Tackle\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Tackle\Support\AppMap;
use Tackle\Support\PathGuard;

/**
 * The semantic map of the Eloquent layer, from the booted application rather
 * than from files: real columns and types off the live connection, casts,
 * relations resolved through reflection, local and global scopes, observers
 * read out of the event dispatcher, the policy off the gate, and the factory
 * with its states.
 *
 * With no argument it returns the index — every model and its table. With a
 * model name it returns that model's full shape, which typically costs around
 * a fifth of what reading the model file plus its migrations costs, and is
 * right about the things reading is wrong about: drifted columns, an observer
 * registered by a package, a global scope quietly filtering every query.
 *
 * Schema and metadata only, never rows — QueryDatabase is the tool for data.
 */
class DescribeModels extends AbstractTool
{
    private readonly AppMap $map;

    public function __construct(PathGuard $guard)
    {
        $this->map = new AppMap($guard);
    }

    public function description(): string
    {
        return 'Describe the application\'s Eloquent models from the running app: real columns and types from the live database, casts, fillable, relations (type, related model, foreign key), local and global scopes, accessors, observers, policy, and factory states. With no argument, lists every model and its table. With a model name, returns that model in full. Use this instead of reading a model file and its migrations — it is cheaper and it is authoritative. Returns schema only, never rows.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'model' => $schema->string()
                ->description('Optional model class (short name or FQCN) to describe in full. Omit to list every model.'),
        ];
    }

    public function handle(Request $request): string
    {
        $only = trim((string) $this->arg($request, 'model', ''));

        if ($only !== '') {
            return $this->map->model($only);
        }

        $models = $this->map->models();

        if ($models === []) {
            return 'No Eloquent models found under app/Models.';
        }

        return "Models:\n"
            .implode("\n", array_map(fn ($class) => '  '.$class, $models))
            ."\n\nCall again with a model name for its columns, casts, relations, scopes, observers, policy, and factory states.";
    }
}
