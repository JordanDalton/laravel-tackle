<?php

namespace Tackle\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Tackle\Support\RouteMap;

/**
 * One route, fully resolved: the middleware stack with groups and aliases
 * expanded, route-model bindings, the FormRequest and its rules, and the
 * authorization guarding it.
 *
 * ListRoutes answers "what exists". This answers "what happens" — the part
 * that decides whether a request gets through, and the part an agent otherwise
 * reconstructs from a kernel, a request class, and a policy while getting the
 * middleware group expansion wrong.
 */
class DescribeRoute extends AbstractTool
{
    public function __construct(private readonly RouteMap $routes) {}

    public function description(): string
    {
        return 'Describe a single route as the framework resolved it: the full middleware stack (groups and aliases expanded), route-model bindings, the FormRequest class with its validation rules, and the authorization (can: middleware and authorize() calls). Accepts a route name, URI, or Controller@method fragment. Use this before editing a controller, middleware, or validation.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'route' => $schema->string()
                ->description('Route name, URI, or Controller@method fragment — e.g. "posts.update", "/posts/{post}", or "PostController@update".'),
        ];
    }

    public function handle(Request $request): string
    {
        return $this->routes->describe((string) $this->arg($request, 'route', ''));
    }
}
