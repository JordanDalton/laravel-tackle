<?php

namespace Tackle\Attributes;

use Attribute;
use Illuminate\Contracts\Container\Container;
use Tackle\Support\PathGuard;

/**
 * Injects a PathGuard configured for the application workspace.
 *
 * Usage:
 *   public function __construct(#[Workspace] PathGuard $guard) {}
 *
 * The resolved PathGuard reads its root from config('ai-code.workspace'),
 * falling back to base_path(). For the healing worktree, PathGuard is
 * instantiated directly with the runtime path instead.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
class Workspace
{
    public static function resolve(self $attribute, Container $container): PathGuard
    {
        return $container->make(PathGuard::class);
    }
}
