<?php

namespace Tackle\Support;

use Tackle\Contracts\InteractionPolicy;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;

/**
 * Prompts the user through the terminal. The default policy — this is what
 * ai:code, ai:fix, and every other interactive command get.
 */
class TerminalInteraction implements InteractionPolicy
{
    public function confirm(string $label, bool $default = true, ?string $hint = null): bool
    {
        echo PHP_EOL;

        return confirm(label: $label, default: $default, hint: $hint ?? '');
    }

    /**
     * A confirm with a third answer: approve and remember it. Not part of the
     * InteractionPolicy contract (adding a method would break custom
     * policies); callers discover it via method_exists and fall back to
     * confirm(). Returns 'yes' | 'no' | 'always'.
     */
    public function confirmWithAlways(string $label, ?string $hint = null): string
    {
        echo PHP_EOL;

        return select(
            label: $label,
            options: [
                'no' => 'No',
                'yes' => 'Yes, once',
                'always' => 'Yes, and always allow this exact command in this project',
            ],
            default: 'no',
            hint: $hint ?? '',
        );
    }

    public function choose(string $question, array $options, bool $multiple = false): string
    {
        echo PHP_EOL;

        if ($multiple) {
            return implode(', ', multiselect(label: $question, options: $options, required: true));
        }

        return select(label: $question, options: $options);
    }

    public function isInteractive(): bool
    {
        return true;
    }

    public function deniedCount(): int
    {
        return 0;
    }
}
