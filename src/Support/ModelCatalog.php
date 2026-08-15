<?php

namespace Tackle\Support;

/**
 * Known per-model token pricing, so budget enforcement stays accurate when
 * the model changes without the user hand-maintaining AI_CODE_PRICE_* env
 * vars. Rates are USD per million tokens at public list price.
 *
 * Built-in entries cover Anthropic models (Tackle's default provider) plus
 * common OpenAI, Google, and xAI models. Non-Anthropic rates are best-effort
 * snapshots — providers reprice without notice, so verify against the
 * provider's pricing page for spend that matters. Any entry can be
 * overridden (and new providers added) via tackle.pricing.models in config.
 */
class ModelCatalog
{
    /**
     * @var array<string, array{input: float, output: float}>
     */
    private const KNOWN = [
        // Anthropic
        'claude-fable-5' => ['input' => 10.00, 'output' => 50.00],
        'claude-opus-5' => ['input' => 5.00, 'output' => 25.00],
        'claude-opus-4-8' => ['input' => 5.00, 'output' => 25.00],
        'claude-opus-4-7' => ['input' => 5.00, 'output' => 25.00],
        'claude-opus-4-6' => ['input' => 5.00, 'output' => 25.00],
        'claude-opus-4-5' => ['input' => 5.00, 'output' => 25.00],
        'claude-opus-4-1' => ['input' => 15.00, 'output' => 75.00],
        'claude-sonnet-5' => ['input' => 3.00, 'output' => 15.00],
        'claude-sonnet-4-6' => ['input' => 3.00, 'output' => 15.00],
        'claude-sonnet-4-5' => ['input' => 3.00, 'output' => 15.00],
        'claude-haiku-4-5' => ['input' => 1.00, 'output' => 5.00],

        // OpenAI (approximate — verify before relying on budget enforcement)
        'gpt-5' => ['input' => 1.25, 'output' => 10.00],
        'gpt-5-mini' => ['input' => 0.25, 'output' => 2.00],
        'gpt-5-nano' => ['input' => 0.05, 'output' => 0.40],
        'gpt-4.1' => ['input' => 2.00, 'output' => 8.00],
        'gpt-4.1-mini' => ['input' => 0.40, 'output' => 1.60],
        'gpt-4o' => ['input' => 2.50, 'output' => 10.00],
        'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60],
        'o3' => ['input' => 2.00, 'output' => 8.00],
        'o4-mini' => ['input' => 1.10, 'output' => 4.40],

        // Google Gemini (approximate — long-context tiers may cost more)
        'gemini-2.5-pro' => ['input' => 1.25, 'output' => 10.00],
        'gemini-2.5-flash' => ['input' => 0.30, 'output' => 2.50],
        'gemini-2.5-flash-lite' => ['input' => 0.10, 'output' => 0.40],

        // xAI Grok (approximate — verify before relying on budget enforcement)
        'grok-4' => ['input' => 3.00, 'output' => 15.00],
        'grok-4-fast' => ['input' => 0.20, 'output' => 0.50],
        'grok-code-fast-1' => ['input' => 0.20, 'output' => 1.50],
        'grok-3' => ['input' => 3.00, 'output' => 15.00],
        'grok-3-mini' => ['input' => 0.30, 'output' => 0.50],
    ];

    /**
     * Every known model with its rates — user-configured entries first,
     * overriding built-ins on collision.
     *
     * @return array<string, array{input: float, output: float}>
     */
    public static function all(): array
    {
        $custom = [];

        foreach ((array) config('tackle.pricing.models', []) as $model => $rates) {
            if (isset($rates['input'], $rates['output'])) {
                $custom[$model] = [
                    'input' => (float) $rates['input'],
                    'output' => (float) $rates['output'],
                ];
            }
        }

        return $custom + self::KNOWN;
    }

    /**
     * Rates for a model, or null when unknown. Matches exact ids first, then
     * by prefix so date-suffixed ids (claude-haiku-4-5-20251001) and
     * Bedrock-prefixed ids (anthropic.claude-opus-5) resolve too.
     *
     * @return array{input: float, output: float}|null
     */
    public static function pricing(string $model): ?array
    {
        $model = self::normalize($model);
        $known = self::all();

        if (isset($known[$model])) {
            return $known[$model];
        }

        // Longest prefix wins so claude-sonnet-4-6-latest never matches a
        // shorter sibling before its own family.
        $best = null;
        $bestLength = 0;

        foreach ($known as $id => $rates) {
            if (str_starts_with($model, $id.'-') && strlen($id) > $bestLength) {
                $best = $rates;
                $bestLength = strlen($id);
            }
        }

        return $best;
    }

    private static function normalize(string $model): string
    {
        return str_starts_with($model, 'anthropic.')
            ? substr($model, strlen('anthropic.'))
            : $model;
    }
}
