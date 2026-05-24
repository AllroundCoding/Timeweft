<?php

namespace Tests\Feature\Narrative;

use App\Narrative\ClaudeCodeNarrator;
use App\Narrative\Saga;
use App\Narrative\TemplateNarrator;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Process;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class ClaudeCodeNarratorTest extends TestCase
{
    private function saga(): Saga
    {
        return new Saga(
            world: 'Sunwell Oasis',
            region: 'Tharados',
            seed: 'vaeris',
            startYear: 1,
            endYear: 2,
            events: [['year' => 1, 'text' => 'Year 1 — the village is founded.', 'type' => 'festival']],
            population: ['founders' => 8, 'born' => 0, 'died' => 0, 'living' => 8],
        );
    }

    private function narrator(?string $model = null): ClaudeCodeNarrator
    {
        return new ClaudeCodeNarrator(
            new Repository(new ArrayStore),
            new TemplateNarrator,
            app(LoggerInterface::class),
            'claude',
            $model,
        );
    }

    public function test_it_runs_the_cli_in_print_mode_with_tools_disabled_and_returns_the_prose(): void
    {
        Process::fake(['*' => Process::result(output: 'A saga told through Max.')]);

        $prose = $this->narrator()->retell($this->saga());

        $this->assertSame('A saga told through Max.', $prose);
        Process::assertRan(function ($process) {
            $command = $process->command;

            return is_array($command)
                && $command[0] === 'claude'
                && in_array('--print', $command, true)
                && in_array('--system-prompt', $command, true)
                && in_array('--tools', $command, true);
        });
    }

    public function test_it_caches_by_fingerprint_so_the_cli_runs_once(): void
    {
        Process::fake(['*' => Process::result(output: 'Once told.')]);

        $narrator = $this->narrator();
        $narrator->retell($this->saga());
        $narrator->retell($this->saga());

        Process::assertRanTimes(fn () => true, 1);
    }

    public function test_it_falls_back_to_the_template_when_the_cli_fails(): void
    {
        Process::fake(['*' => Process::result(output: '', errorOutput: 'not logged in', exitCode: 1)]);

        $prose = $this->narrator()->retell($this->saga());

        // The deterministic template stands in — a missing or unauthenticated CLI never breaks the view.
        $this->assertStringContainsString('Sunwell Oasis', $prose);
    }
}
