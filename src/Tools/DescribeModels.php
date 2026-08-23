<?php

namespace Tackle\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Laravel\Ai\Tools\Request;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Tackle\Support\PathGuard;
use Throwable;

/**
 * The Eloquent model graph via reflection — each model's table, fillable,
 * casts, and relationships — so the agent knows the domain shape without
 * reading every model file. Relations are read from declared return types
 * (no method execution).
 */
class DescribeModels extends AbstractTool
{
    public function __construct(private readonly PathGuard $guard) {}

    public function description(): string
    {
        return 'Describe the application\'s Eloquent models — table, fillable, casts, and relationships (name + related model). With no argument, lists all models; with a class name, describes that one. Relationships are read from method return types.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'model' => $schema->string()
                ->description('Optional model class (short or FQCN) to describe. Omit to list all.'),
        ];
    }

    public function handle(Request $request): string
    {
        $only = trim((string) $request->string('model', ''));

        $models = $this->discoverModels();

        if ($models === []) {
            return 'No Eloquent models found under app/Models.';
        }

        if ($only !== '') {
            $match = collect($models)->first(
                fn ($c) => $c === $only || class_basename($c) === $only || class_basename($c) === class_basename($only),
            );

            return $match ? $this->describe($match) : "Model '{$only}' not found. Available: ".implode(', ', array_map('class_basename', $models));
        }

        $out = ['Models:'];
        foreach ($models as $class) {
            $out[] = '';
            $out[] = $this->describe($class);
        }

        return implode("\n", $out);
    }

    private function describe(string $class): string
    {
        try {
            /** @var Model $model */
            $model = new $class;
            $lines = [class_basename($class).'  (table: '.$model->getTable().')'];

            $fillable = $model->getFillable();
            if ($fillable !== []) {
                $lines[] = '  fillable: '.implode(', ', $fillable);
            }

            $casts = $model->getCasts();
            if ($casts !== []) {
                $lines[] = '  casts: '.collect($casts)->map(fn ($t, $k) => "{$k}={$t}")->implode(', ');
            }

            $relations = $this->relations(new ReflectionClass($class));
            if ($relations !== []) {
                $lines[] = '  relations: '.implode(', ', $relations);
            }

            return implode("\n", $lines);
        } catch (Throwable $e) {
            return class_basename($class).'  (could not reflect: '.$e->getMessage().')';
        }
    }

    /**
     * @return list<string>
     */
    private function relations(ReflectionClass $ref): array
    {
        $relations = [];

        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getNumberOfParameters() > 0 || $method->class !== $ref->getName()) {
                continue;
            }

            $type = $method->getReturnType();
            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin() && is_subclass_of($type->getName(), Relation::class)) {
                $relations[] = $method->getName().' ('.class_basename($type->getName()).')';
            }
        }

        return $relations;
    }

    /**
     * @return list<string>
     */
    private function discoverModels(): array
    {
        $dir = rtrim($this->guard->workspace(), '/').'/app/Models';

        if (! is_dir($dir)) {
            return [];
        }

        $models = [];
        foreach (glob($dir.'/*.php') ?: [] as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');
            try {
                if (class_exists($class) && is_subclass_of($class, Model::class) && ! (new ReflectionClass($class))->isAbstract()) {
                    $models[] = $class;
                }
            } catch (Throwable) {
                // skip unloadable files
            }
        }

        sort($models);

        return $models;
    }
}
