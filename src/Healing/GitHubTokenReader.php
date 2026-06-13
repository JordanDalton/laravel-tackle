<?php

namespace Tackle\Healing;

class GitHubTokenReader
{
    public function token(): ?string
    {
        // 1. Explicit config / env
        $token = config('ai-code.healing.github_token');
        if ($token) {
            return $token;
        }

        // 2. GitHub CLI hosts file  (~/.config/gh/hosts.yml)
        $hostsFile = ($_SERVER['HOME'] ?? posix_getpwuid(posix_getuid())['dir'] ?? '') . '/.config/gh/hosts.yml';
        if (file_exists($hostsFile)) {
            $content = file_get_contents($hostsFile);
            if ($content && preg_match('/oauth_token:\s*([^\n\r]+)/', $content, $m)) {
                $candidate = trim($m[1]);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        return null;
    }
}
