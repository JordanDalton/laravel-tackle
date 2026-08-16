<?php

namespace Tackle\Upgrade;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Deterministic composer inspection behind ai:upgrade — no model involved.
 * Finds direct dependencies with a newer major available and explains what
 * blocks each one, so the plan phase starts from facts instead of guesses.
 */
class Auditor
{
    public function __construct(private string $workspace) {}

    /**
     * Direct dependencies whose latest release is a new major version.
     *
     * @return list<array{name: string, version: string, latest: string, description: string}>
     */
    public function majors(): array
    {
        $result = Process::path($this->workspace)
            ->timeout(300)
            ->run(['composer', 'outdated', '--direct', '--format=json', '--no-interaction', '--no-ansi']);

        if ($result->failed()) {
            throw new RuntimeException(
                'composer outdated failed: '.trim($result->errorOutput() ?: $result->output())
            );
        }

        $data = json_decode($result->output(), true);

        if (! is_array($data)) {
            throw new RuntimeException('composer outdated returned unparseable output.');
        }

        $majors = [];

        foreach ($data['installed'] ?? [] as $package) {
            $current = self::majorOf($package['version'] ?? '');
            $latest = self::majorOf($package['latest'] ?? '');

            if ($current === null || $latest === null || $latest <= $current) {
                continue;
            }

            $majors[] = [
                'name' => (string) $package['name'],
                'version' => (string) $package['version'],
                'latest' => (string) $package['latest'],
                'description' => (string) ($package['description'] ?? ''),
            ];
        }

        return $majors;
    }

    /**
     * What blocks upgrading a package to the given constraint, as reported by
     * `composer why-not`. Empty constraint derives one from the target version.
     */
    public function whyNot(string $package, string $constraint): string
    {
        $result = Process::path($this->workspace)
            ->timeout(300)
            ->run(['composer', 'why-not', $package, $constraint, '--no-interaction', '--no-ansi']);

        return trim($result->output().$result->errorOutput());
    }

    /**
     * The pre-session audit context that seeds one package's first prompt:
     * the majors overview, the package's known blockers, and — in a batch —
     * a scope fence so the agent leaves the queued packages to their own
     * sessions.
     *
     * @param  list<string>  $batch
     * @param  list<array{name: string, version: string, latest: string, description: string}>  $majors
     */
    public function promptContext(string $package, array $batch, array $majors): string
    {
        $target = null;
        $context = "Audit of direct dependencies with a new major available:\n";

        foreach ($majors as $major) {
            $context .= "- {$major['name']}: {$major['version']} installed, {$major['latest']} available\n";

            if ($major['name'] === $package) {
                $target = $major;
            }
        }

        if ($target !== null) {
            $constraint = self::constraintFor($target['latest']);
            $blockers = $this->whyNot($package, $constraint);

            if ($blockers !== '') {
                $context .= "\n`composer why-not {$package} {$constraint}` reports:\n{$blockers}\n";
            }
        } else {
            $context .= "\nNote: {$package} did not appear in the major-upgrade audit — verify its installed and latest versions yourself before planning.\n";
        }

        $others = array_values(array_diff($batch, [$package]));

        if ($others !== []) {
            $context .= "\nScope: this session upgrades ONLY {$package}. These packages are queued for their own separate "
                .'sessions afterwards — do not upgrade them here unless the dependency solver forces them to move as part '
                ."of {$package}'s chain: ".implode(', ', $others)."\n";
        }

        return $context;
    }

    /**
     * A why-not constraint for a target version: "v12.4.2" → "^12.0".
     */
    public static function constraintFor(string $version): string
    {
        $major = self::majorOf($version);

        return $major === null ? $version : "^{$major}.0";
    }

    /**
     * The major version number, or null for non-semver versions (dev-main, …).
     */
    private static function majorOf(string $version): ?int
    {
        return preg_match('/^v?(\d+)\./', trim($version), $m) ? (int) $m[1] : null;
    }
}
