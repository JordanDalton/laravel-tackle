<?php

namespace Tackle\Support;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * The resolved shape of a route: the middleware that actually runs, the
 * validation that actually applies, and the authorization that actually
 * guards it.
 *
 * `route:list` gives a URI and an action name. What decides whether a request
 * succeeds is the expanded middleware stack, the FormRequest's rules, and the
 * gate behind `can:` — three things spread across a kernel, a request class,
 * and a policy, which an agent otherwise reconstructs by reading four files
 * and still gets the middleware group expansion wrong.
 *
 * Middleware and bindings come from the booted router, so they are exact.
 * Validation rules are best-effort by nature: rules() commonly depends on the
 * request it was never given here, so a failed call falls back to the method
 * source rather than to silence.
 */
class RouteMap
{
    /** The HTTP kernel is worth resolving once per process, not per route. */
    private bool $kernelBooted = false;

    /**
     * Describe the routes matching a URI, name, or action fragment.
     */
    public function describe(string $query): string
    {
        $query = trim($query);

        if ($query === '') {
            return 'Pass a route URI, name, or action fragment (for example "posts.show", "/posts/{post}", or "PostController@show").';
        }

        $matches = $this->match($query);

        if ($matches === []) {
            return "No route matches '{$query}'. Use ListRoutes to see what is registered.";
        }

        if (count($matches) > 1) {
            $exact = array_values(array_filter(
                $matches,
                fn (RoutingRoute $route) => $route->getName() === $query || $route->uri() === ltrim($query, '/'),
            ));

            if (count($exact) === 1) {
                $matches = $exact;
            }
        }

        if (count($matches) > 6) {
            return "'{$query}' matches ".count($matches)." routes. Narrow it down — the first few are:\n"
                .implode("\n", array_map(fn ($route) => '  '.$this->summary($route), array_slice($matches, 0, 6)));
        }

        return implode("\n\n", array_map(fn ($route) => $this->detail($route), $matches));
    }

    /**
     * @return list<RoutingRoute>
     */
    private function match(string $query): array
    {
        $needle = strtolower(ltrim($query, '/'));
        $matches = [];

        try {
            $routes = Route::getRoutes();
        } catch (Throwable) {
            return [];
        }

        foreach ($routes as $route) {
            $haystack = strtolower(implode(' ', [
                $route->uri(),
                (string) $route->getName(),
                $route->getActionName(),
            ]));

            if (str_contains($haystack, $needle)) {
                $matches[] = $route;
            }
        }

        return $matches;
    }

    private function summary(RoutingRoute $route): string
    {
        return sprintf(
            '%-10s %-40s %-24s %s',
            implode('|', array_diff($route->methods(), ['HEAD'])),
            '/'.ltrim($route->uri(), '/'),
            (string) $route->getName(),
            $route->getActionName(),
        );
    }

    private function detail(RoutingRoute $route): string
    {
        $out = [$this->summary($route)];

        $out[] = 'Middleware   '.($this->middleware($route) ?: '(none)');

        if ($bindings = $this->bindings($route)) {
            $out[] = 'Bindings     '.implode('  ', $bindings);
        }

        if ($authorization = $this->authorization($route)) {
            $out[] = 'Authorizes   '.$authorization;
        }

        foreach ($this->formRequests($route) as $block) {
            $out[] = '';
            $out[] = $block;
        }

        return implode("\n", $out);
    }

    /**
     * The middleware that actually runs, with groups and aliases expanded —
     * `web` becomes the eight classes it stands for, so the agent can see the
     * session, CSRF, and binding middleware it is really working behind.
     */
    private function middleware(RoutingRoute $route): string
    {
        try {
            $this->bootHttpKernel();

            $router = app('router');

            $declared = $route->gatherMiddleware();

            $resolved = method_exists($router, 'gatherRouteMiddleware')
                ? $router->gatherRouteMiddleware($route)
                : $declared;

            $short = array_map(fn ($m) => is_string($m) ? class_basename($m) : (is_object($m) ? class_basename($m) : '?'), $resolved);

            $declaredStr = implode(', ', array_map(fn ($m) => is_string($m) ? $m : '(closure)', $declared));
            $resolvedStr = implode(', ', array_unique($short));

            return $declaredStr === $resolvedStr
                ? $declaredStr
                : $declaredStr."\n             resolves to: ".$resolvedStr;
        } catch (Throwable $e) {
            return '(could not resolve: '.$e->getMessage().')';
        }
    }

    /**
     * Make sure the middleware groups actually exist before resolving them.
     *
     * In Laravel 11+ the HTTP kernel's constructor is what registers `web`,
     * `api`, and friends on the router. Tackle always runs from the console,
     * where that kernel is never instantiated — so `web` resolved to whatever
     * a service provider happened to add and nothing else. On a real Fortify
     * app that meant one class where a request would run eleven.
     *
     * Resolving the kernel late has its own catch: its registration replaces a
     * group rather than merging into it, so middleware a provider had already
     * pushed onto `web` would vanish. In a real request the kernel is
     * constructed before providers boot and both end up in the stack, so the
     * truthful answer is the union — snapshot what is there, resolve, and put
     * the extras back. The order within a group is not guaranteed to match
     * request time, but the set is right, and the set is what the question is
     * about.
     *
     * Nothing here handles a request; it only populates the router's group
     * registry in a process that is never going to serve one.
     */
    private function bootHttpKernel(): void
    {
        if ($this->kernelBooted) {
            return;
        }

        $this->kernelBooted = true;

        try {
            $router = app('router');
            $registered = $router->getMiddlewareGroups();

            app(HttpKernel::class);

            foreach ($registered as $group => $stack) {
                foreach ($stack as $middleware) {
                    $router->pushMiddlewareToGroup($group, $middleware);
                }
            }
        } catch (Throwable) {
            // A console-only application has no HTTP kernel to boot. The
            // route's own middleware is still reported; only group expansion
            // is unavailable, which is the honest outcome there.
        }
    }

    /**
     * Route-model bindings, so the agent knows `{post}` arrives as a Post
     * resolved by slug rather than as a string it has to look up.
     *
     * @return list<string>
     */
    private function bindings(RoutingRoute $route): array
    {
        $bindings = [];

        try {
            $fields = $route->bindingFields();

            foreach ($route->signatureParameters() as $parameter) {
                $type = $parameter->getType();

                if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                    continue;
                }

                if (! is_subclass_of($type->getName(), Model::class)) {
                    continue;
                }

                $name = $parameter->getName();

                $bindings[] = '{'.$name.'}→'.class_basename($type->getName())
                    .(isset($fields[$name]) ? ' by '.$fields[$name] : '');
            }
        } catch (Throwable) {
            return $bindings;
        }

        return $bindings;
    }

    /**
     * Authorization comes from two places and only one of them is reflectable.
     * `can:` middleware is exact; a controller calling $this->authorize() is
     * read out of the method source, so it is reported as a source match
     * rather than as a resolved fact.
     */
    private function authorization(RoutingRoute $route): string
    {
        $parts = [];

        try {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'can:')) {
                    $parts[] = $middleware.' (middleware)';
                }
            }

            $source = $this->actionSource($route);

            if ($source !== null && preg_match_all('/(?:\$this->authorize|Gate::(?:authorize|allows|denies)|authorizeForUser)\s*\(\s*[\'"]([\w.-]+)[\'"]/', $source, $m)) {
                foreach (array_unique($m[1]) as $ability) {
                    $parts[] = $ability.' (called in the controller — read from source, not resolved)';
                }
            }
        } catch (Throwable) {
            return implode('  ', $parts);
        }

        return implode('  ', $parts);
    }

    /**
     * The validation that applies to this route.
     *
     * rules() frequently depends on the request — `Rule::unique(...)->ignore($this->route('post'))`
     * throws outside a request cycle. When the call fails, the method source
     * is returned verbatim with a note: ten lines of a rules() body is still
     * cheaper than the agent reading the whole FormRequest, and it is honest
     * about being unresolved rather than presenting a guess as the answer.
     *
     * @return list<string>
     */
    private function formRequests(RoutingRoute $route): array
    {
        $blocks = [];

        try {
            $method = $this->actionMethod($route);
        } catch (Throwable) {
            return [];
        }

        if ($method === null) {
            return [];
        }

        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $class = $type->getName();

            if (! is_subclass_of($class, FormRequest::class)) {
                continue;
            }

            $blocks[] = 'FormRequest  '.$class."\n".$this->rules($class);
        }

        return $blocks;
    }

    private function rules(string $class): string
    {
        try {
            $request = new $class;

            if (! method_exists($request, 'rules')) {
                return '  (no rules() method)';
            }

            $rules = $request->rules();

            if (! is_array($rules) || $rules === []) {
                return '  (rules() returned nothing)';
            }

            $lines = [];

            foreach ($rules as $field => $rule) {
                $lines[] = sprintf('  %-24s %s', $field, $this->stringifyRule($rule));
            }

            return implode("\n", $lines);
        } catch (Throwable $e) {
            $source = $this->methodSource($class, 'rules');

            return $source === null
                ? '  (rules() could not be read: '.$e->getMessage().')'
                : "  rules() depends on the request and could not be evaluated here, so this is its source:\n"
                    .$this->indent($source);
        }
    }

    private function stringifyRule(mixed $rule): string
    {
        if (is_string($rule)) {
            return $rule;
        }

        if (is_array($rule)) {
            return implode('|', array_map(fn ($r) => $this->stringifyRule($r), $rule));
        }

        if (is_object($rule)) {
            return $rule instanceof \Stringable ? (string) $rule : class_basename($rule);
        }

        return is_scalar($rule) ? (string) $rule : gettype($rule);
    }

    private function actionMethod(RoutingRoute $route): ?ReflectionMethod
    {
        $action = $route->getActionName();

        if ($action === 'Closure' || ! str_contains($action, '@')) {
            // Invokable controllers register as Class@__invoke, so only true
            // closures fall through here.
            return null;
        }

        [$class, $method] = explode('@', $action, 2);

        if (! class_exists($class) || ! method_exists($class, $method)) {
            return null;
        }

        return new ReflectionMethod($class, $method);
    }

    private function actionSource(RoutingRoute $route): ?string
    {
        try {
            $method = $this->actionMethod($route);
        } catch (Throwable) {
            return null;
        }

        return $method === null ? null : $this->sourceOf($method);
    }

    private function methodSource(string $class, string $method): ?string
    {
        try {
            if (! class_exists($class) || ! method_exists($class, $method)) {
                return null;
            }

            return $this->sourceOf(new ReflectionMethod($class, $method));
        } catch (Throwable) {
            return null;
        }
    }

    private function sourceOf(ReflectionMethod $method): ?string
    {
        $file = $method->getFileName();
        $start = $method->getStartLine();
        $end = $method->getEndLine();

        if ($file === false || $start === false || $end === false) {
            return null;
        }

        $lines = @file($file);

        if ($lines === false) {
            return null;
        }

        return rtrim(implode('', array_slice($lines, $start - 1, $end - $start + 1)));
    }

    /**
     * Re-indent a source block under our own margin, keeping the code's own
     * relative indentation — a rules() array reads as an array, not as a wall.
     */
    private function indent(string $text): string
    {
        $lines = explode("\n", $text);

        $common = null;

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $leading = strlen($line) - strlen(ltrim($line));
            $common = $common === null ? $leading : min($common, $leading);
        }

        return implode("\n", array_map(
            fn ($line) => trim($line) === '' ? '' : '    '.substr($line, $common ?? 0),
            $lines,
        ));
    }

    /**
     * Route classes are resolved lazily so this file loads on installs where
     * the routing component is present but no routes are registered.
     */
    public function total(): int
    {
        try {
            return count(Route::getRoutes());
        } catch (Throwable) {
            return 0;
        }
    }
}
