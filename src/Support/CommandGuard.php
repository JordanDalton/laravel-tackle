<?php

namespace Tackle\Support;

class CommandGuard
{
    /**
     * Resolve an env-keyed or flat list down to the patterns for the current environment.
     *
     * Flat list  → returned as-is (backward compatible).
     * Env-keyed  → returns $lists[APP_ENV] ?? $lists['*'] ?? [].
     *
     * @param  array<string|int, mixed>  $lists
     * @return list<string>
     */
    public function resolveList(array $lists): array
    {
        if (array_is_list($lists)) {
            return $lists;
        }

        $env = app()->environment();

        return $lists[$env] ?? $lists['*'] ?? [];
    }

    /**
     * Returns true if the command matches any pattern in the given list.
     *
     * @param  list<string>  $patterns  Glob patterns or literal prefixes.
     */
    public function matches(string $command, array $patterns): bool
    {
        $command = trim($command);

        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $command, FNM_CASEFOLD)) {
                return true;
            }

            if (str_starts_with($command, rtrim($pattern, '*'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns null if the command is allowed by the given allowlist.
     * Returns a refusal message if it is not.
     *
     * @param  list<string>  $allowlist  Glob patterns or literal prefixes.
     */
    public function check(string $command, array $allowlist): ?string
    {
        if ($this->matches($command, $allowlist)) {
            return null;
        }

        return "Command '{$command}' is not in the allowlist. Allowed patterns: "
            .implode(', ', $allowlist);
    }
}
