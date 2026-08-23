<?php

namespace Tackle\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Tools\Request;
use Tackle\Support\PathGuard;
use Throwable;

/**
 * The application's stack at a glance — Laravel/PHP versions, key drivers, and
 * the notable installed packages (Livewire vs Inertia, Pest vs PHPUnit,
 * Fortify/Breeze, Filament, Sanctum, …) — so the agent knows what it's working
 * in before it writes anything.
 */
class AppInfo extends AbstractTool
{
    /** Packages worth calling out for stack detection. */
    private const NOTABLE = [
        'livewire/livewire', 'inertiajs/inertia-laravel', 'filament/filament',
        'laravel/fortify', 'laravel/breeze', 'laravel/jetstream', 'laravel/sanctum',
        'laravel/passport', 'laravel/nova', 'laravel/scout', 'laravel/horizon',
        'laravel/telescope', 'laravel/pennant', 'laravel/cashier', 'laravel/pulse',
        'pestphp/pest', 'phpunit/phpunit', 'nunomaduro/larastan', 'laravel/pint',
        'spatie/laravel-permission', 'spatie/laravel-data',
    ];

    public function __construct(private readonly PathGuard $guard) {}

    public function description(): string
    {
        return 'Report the application stack: Laravel/PHP versions and drivers (via artisan about) plus the notable installed packages (Livewire/Inertia/Filament, Pest/PHPUnit, Fortify/Breeze, Sanctum, …). Use it to learn the stack before writing code.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): string
    {
        $out = [];

        try {
            $result = Process::path($this->guard->workspace())->timeout(30)
                ->run(['php', 'artisan', 'about', '--json']);

            if ($result->successful()) {
                $about = json_decode(trim($result->output()), true);
                $env = $about['environment'] ?? [];
                $drivers = $about['drivers'] ?? [];
                if ($env !== []) {
                    $out[] = 'Environment: '.collect($env)
                        ->only(['application_name', 'laravel_version', 'php_version', 'environment'])
                        ->map(fn ($v, $k) => "{$k}={$v}")->implode('  ');
                }
                if ($drivers !== []) {
                    $out[] = 'Drivers: '.collect($drivers)->map(fn ($v, $k) => "{$k}={$v}")->implode('  ');
                }
            }
        } catch (Throwable) {
            // fall through to package detection
        }

        $packages = $this->notablePackages();
        if ($packages !== []) {
            $out[] = '';
            $out[] = 'Notable packages: '.implode(', ', $packages);
        }

        return $out === [] ? 'Could not determine the application stack.' : implode("\n", $out);
    }

    /**
     * @return list<string>
     */
    private function notablePackages(): array
    {
        $lock = rtrim($this->guard->workspace(), '/').'/composer.lock';
        $installed = [];

        if (is_file($lock)) {
            $data = json_decode((string) @file_get_contents($lock), true);
            foreach (array_merge($data['packages'] ?? [], $data['packages-dev'] ?? []) as $pkg) {
                $installed[$pkg['name'] ?? ''] = $pkg['version'] ?? '';
            }
        }

        $found = [];
        foreach (self::NOTABLE as $name) {
            if (isset($installed[$name])) {
                $found[] = $name.' '.$installed[$name];
            }
        }

        return $found;
    }
}
