<?php

namespace Tackle\Healing;

class GitHubTokenReader
{
    public function token(): ?string
    {
        // 1. Explicit config / env var
        $token = config('ai-code.healing.github_token');
        if ($token) {
            return $token;
        }

        // 2. GitHub CLI — most reliable; works regardless of HOME or YAML format
        $ghToken = $this->fromGhCli();
        if ($ghToken) {
            return $ghToken;
        }

        // 3. Fallback: parse ~/.config/gh/hosts.yml directly
        $home = $_SERVER['HOME'] ?? $_ENV['HOME'] ?? getenv('HOME') ?? '';
        if (!$home && function_exists('posix_getpwuid') && function_exists('posix_getuid')) {
            $home = posix_getpwuid(posix_getuid())['dir'] ?? '';
        }

        if ($home) {
            $hostsFile = $home . '/.config/gh/hosts.yml';
            if (file_exists($hostsFile)) {
                $content = file_get_contents($hostsFile);
                if ($content && preg_match('/oauth_token:\s*([^\n\r]+)/', $content, $m)) {
                    $candidate = trim($m[1]);
                    if ($candidate !== '') {
                        return $candidate;
                    }
                }
            }
        }

        return null;
    }

    private function fromGhCli(): ?string
    {
        // Suppress stderr so a missing/unauthenticated gh doesn't pollute logs.
        $output = @shell_exec('gh auth token 2>/dev/null');
        if ($output === null) {
            return null;
        }

        $token = trim($output);

        return $token !== '' ? $token : null;
    }
}
