<?php

namespace Tackle\Attributes;

use Attribute;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Container\ContextualAttribute;

/**
 * Injects the configured AI model identifier.
 *
 * Usage:
 *   public function __construct(#[AiModel] string $model = 'claude-sonnet-4-6') {}
 *
 * Reads config('ai-code.model'). The default value on the parameter is
 * used only when the class is instantiated directly (e.g. in tests); the
 * container always injects the live config value.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
class AiModel implements ContextualAttribute
{
    public static function resolve(self $attribute, Container $container): string
    {
        return config('ai-code.model', 'claude-sonnet-4-6');
    }
}
