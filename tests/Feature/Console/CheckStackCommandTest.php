<?php

namespace Tests\Feature\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CheckStackCommandTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_the_committed_cheatsheet_is_in_sync_with_composer_lock(): void
    {
        // Doubles as the CI guard: a dependency bump that isn't reflected in the
        // cheatsheet fails here (and in the workflow) until the table is regenerated.
        $exitCode = Artisan::call('docs:check-stack', ['--check' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode, Artisan::output());
    }

    public function test_it_regenerates_a_stale_version_block(): void
    {
        $path = $this->fixture($this->withBlock('| `php` | `^0.0` |'));

        $exitCode = Artisan::call('docs:check-stack', ['--path' => $path]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $contents = (string) file_get_contents($path);
        $this->assertStringContainsString('| `php` | `^', $contents);
        $this->assertStringContainsString('| `laravel/framework` |', $contents);
        $this->assertStringNotContainsString('`^0.0`', $contents);
        $this->assertStringContainsString('surrounding prose stays put', $contents);
    }

    public function test_check_mode_fails_on_a_stale_block_without_writing(): void
    {
        $stale = $this->withBlock('| `php` | `^0.0` |');
        $path = $this->fixture($stale);

        $exitCode = Artisan::call('docs:check-stack', ['--path' => $path, '--check' => true]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertSame($stale, (string) file_get_contents($path), 'check mode must not write');
    }

    public function test_it_fails_when_the_markers_are_missing(): void
    {
        $path = $this->fixture("# No markers here\n");

        $this->assertSame(Command::FAILURE, Artisan::call('docs:check-stack', ['--path' => $path]));
    }

    public function test_it_fails_when_the_file_is_missing(): void
    {
        $path = sys_get_temp_dir().'/does-not-exist-'.uniqid().'.md';

        $this->assertSame(Command::FAILURE, Artisan::call('docs:check-stack', ['--path' => $path]));
    }

    private function withBlock(string $body): string
    {
        return "# Fixture\n\nsurrounding prose stays put\n\n"
            ."<!-- stack:versions:start -->\n{$body}\n<!-- stack:versions:end -->\n\nmore prose\n";
    }

    private function fixture(string $contents): string
    {
        $path = sys_get_temp_dir().'/cheatsheet-'.uniqid().'.md';
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }
}
