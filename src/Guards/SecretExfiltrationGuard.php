<?php

namespace Tackle\Guards;

/**
 * Blocks writing code that reads secrets in a way meant to surface them —
 * the WriteFile-a-test-that-dumps-env()-then-RunTests path. PathGuard stops a
 * tool from reading .env directly; this stops the agent from writing code that
 * a subprocess then runs to read it. Defense-in-depth, not containment.
 *
 * Register as a pre_tool hook matched to WriteFile and EditFile.
 */
class SecretExfiltrationGuard extends AbstractGuard
{
    /** @var array<int, string> */
    private const DEFAULT_PATTERNS = [
        '\benv\s*\(\s*[\'"][^\'"]*(?:KEY|SECRET|TOKEN|PASSWORD|DSN)[^\'"]*[\'"]',
        '\bgetenv\s*\(',
        '\$_ENV\b',
        '\$_SERVER\s*\[\s*[\'"][^\'"]*(?:KEY|SECRET|TOKEN|PASSWORD)',
        'config\s*\(\s*[\'"]app\.key[\'"]',
        'config\s*\(\s*[\'"]services\.[^\'"]+\.(?:secret|key|token)',
        '(?:file_get_contents|fopen|parse_ini_file)\s*\([^)]*\.env',
    ];

    public function handle(array $payload): null|false|string
    {
        if ($this->mode('secrets', 'block') === 'off') {
            return null;
        }

        $hit = $this->firstMatch($this->candidateText($payload['arguments'] ?? []), $this->patterns());

        if ($hit === null) {
            return null;
        }

        return 'Refused by SecretExfiltrationGuard: this writes code that reads a secret ('
            .trim($hit).'). Do not echo credentials or dump env() into files, tests, or output. '
            .'If you need to verify configuration, describe the check in words instead of surfacing the value.';
    }

    /**
     * @return array<int, string>
     */
    private function patterns(): array
    {
        $extra = config('tackle.guard.secret_patterns', []);

        return [...self::DEFAULT_PATTERNS, ...(is_array($extra) ? array_values(array_filter($extra, 'is_string')) : [])];
    }
}
