<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('docs:check-stack {--check : Report drift and fail instead of writing} {--path= : Cheatsheet path (defaults to docs/laravel-cheatsheet.md)}')]
#[Description('Regenerate the installed-version table in the stack cheatsheet from composer.lock')]
class CheckStack extends Command
{
    private const DEFAULT_PATH = 'docs/laravel-cheatsheet.md';

    private const MARKER_START = '<!-- stack:versions:start -->';

    private const MARKER_END = '<!-- stack:versions:end -->';

    /**
     * The ecosystem packages worth pinning in the cheatsheet, in display order.
     * Anything not installed renders as "—" — informative until it lands
     * (e.g. Larastan / Rector arrive with TWT-186).
     *
     * @var list<string>
     */
    private const STACK = [
        'laravel/framework',
        'laravel/tinker',
        'laravel/prompts',
        'laravel/pail',
        'laravel/boost',
        'laravel/mcp',
        'laravel/pint',
        'larastan/larastan',
        'rector/rector',
        'phpunit/phpunit',
        'mockery/mockery',
        'nunomaduro/collision',
        'fakerphp/faker',
        'nesbot/carbon',
    ];

    public function handle(): int
    {
        $path = ((string) $this->option('path')) ?: base_path(self::DEFAULT_PATH);

        if (! is_file($path)) {
            $this->error("Cheatsheet not found at {$path}.");

            return self::FAILURE;
        }

        $current = (string) file_get_contents($path);
        $updated = $this->spliceVersionTable($current);

        if ($updated === null) {
            $this->error('Could not find the stack:versions markers in '.$path.'.');

            return self::FAILURE;
        }

        if ($updated === $current) {
            $this->info('Stack cheatsheet version table is up to date.');

            return self::SUCCESS;
        }

        if ((bool) $this->option('check')) {
            $this->error('Stack cheatsheet version table is stale. Run `php artisan docs:check-stack` and commit the result.');

            return self::FAILURE;
        }

        file_put_contents($path, $updated);
        $this->info('Updated the stack cheatsheet version table.');

        return self::SUCCESS;
    }

    /**
     * Replace the content between the markers with a freshly generated table.
     * Returns null when the markers are missing or malformed.
     */
    private function spliceVersionTable(string $content): ?string
    {
        $startPos = strpos($content, self::MARKER_START);
        $endPos = strpos($content, self::MARKER_END);

        if ($startPos === false || $endPos === false || $endPos < $startPos) {
            return null;
        }

        $before = substr($content, 0, $startPos + strlen(self::MARKER_START));
        $after = substr($content, $endPos);

        return $before."\n".$this->versionTable()."\n".$after;
    }

    private function versionTable(): string
    {
        $installed = $this->installedVersions();

        $rows = ['| Package | Version |', '| --- | --- |'];
        $rows[] = $this->row('php', $this->phpConstraint());

        foreach (self::STACK as $package) {
            $rows[] = $this->row($package, $installed[$package] ?? null);
        }

        return implode("\n", $rows);
    }

    private function row(string $package, ?string $version): string
    {
        return sprintf('| `%s` | %s |', $package, $version === null ? '—' : "`{$version}`");
    }

    /**
     * Map of installed composer package name => version, from composer.lock.
     *
     * @return array<string, string>
     */
    private function installedVersions(): array
    {
        $lock = $this->readJson(base_path('composer.lock'));
        $packages = array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []);

        $versions = [];
        foreach ($packages as $package) {
            if (isset($package['name'], $package['version'])) {
                $versions[$package['name']] = (string) $package['version'];
            }
        }

        return $versions;
    }

    private function phpConstraint(): string
    {
        $composer = $this->readJson(base_path('composer.json'));

        return (string) ($composer['require']['php'] ?? 'unknown');
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        return json_decode((string) file_get_contents($path), true) ?? [];
    }
}
