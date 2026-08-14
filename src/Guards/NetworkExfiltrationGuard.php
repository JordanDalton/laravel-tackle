<?php

namespace Tackle\Guards;

/**
 * Flags the exfiltration *transport* — outbound network in agent-authored code
 * or shell commands — so a leaked secret has no way out. Pairs with
 * SecretExfiltrationGuard: one blocks reading the secret, this blocks sending
 * it. Defense-in-depth, not containment.
 *
 * Register as a pre_tool hook matched to WriteFile, EditFile, and RunShell.
 * mode (tackle.guard.network): 'block' (default), 'confirm', or 'off'.
 */
class NetworkExfiltrationGuard extends AbstractGuard
{
    /** @var array<int, string> */
    private const CODE_PATTERNS = [
        '\bHttp::(?:get|post|put|patch|delete|send|with|acceptJson)',
        '\b(?:file_get_contents|fopen)\s*\(\s*[\'"]https?:\/\/',
        '\b(?:fsockopen|stream_socket_client|curl_exec)\b',
        '\bwebhook\b',
    ];

    /** @var array<int, string> */
    private const SHELL_PATTERNS = [
        '\b(?:curl|wget)\b(?![^|]*localhost)(?![^|]*127\.0\.0\.1)',
        '\|\s*(?:sh|bash|zsh)\b',
        '\bnc\b.*\d{1,5}',
    ];

    public function handle(array $payload): null|false|string
    {
        $mode = $this->mode('network', 'block');

        if ($mode === 'off') {
            return null;
        }

        $arguments = $payload['arguments'] ?? [];
        $isShell = ($payload['tool'] ?? '') === 'RunShell';

        $text = $isShell
            ? (string) ($arguments['command'] ?? '')
            : $this->candidateText($arguments);

        $hit = $this->firstMatch($text, $isShell ? self::SHELL_PATTERNS : self::CODE_PATTERNS);

        if ($hit === null) {
            return null;
        }

        // 'confirm' downgrades to a warning the agent must justify rather than
        // an outright block — useful when the project legitimately makes
        // outbound calls. The block message doubles as that prompt.
        $verb = $mode === 'confirm' ? 'Flagged' : 'Refused';

        return "{$verb} by NetworkExfiltrationGuard: this introduces an outbound network call ("
            .trim($hit).'). Agent-authored code and shell commands should not send data to external '
            .'hosts — this is the path a leaked secret would take. If the outbound call is genuinely '
            .'required, state why and what host it targets; otherwise use a Tackle tool instead.';
    }
}
