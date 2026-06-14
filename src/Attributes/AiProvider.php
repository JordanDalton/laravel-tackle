<?php

namespace Tackle\Attributes;

use Attribute;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Container\ContextualAttribute;

/**
 * Injects the configured laravel/ai provider name.
 *
 * Usage:
 *   public function __construct(#[AiProvider] string $provider = 'anthropic') {}
 *
 * Reads config('ai-code.provider'). The default value on the parameter is
 * used only when the class is instantiated directly (e.g. in tests); the
 * container always injects the live config value.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
class AiProvider implements ContextualAttribute
{
    public static function resolve(self $attribute, Container $container): string
    {
        return config('ai-code.provider', 'anthropic');
    }
}
