<?php

namespace Tests\Feature\Narrative;

use App\Narrative\ClaudeCodeNarrator;
use App\Narrative\LlmNarrator;
use App\Narrative\Narrator;
use App\Narrative\Saga;
use App\Narrative\TemplateNarrator;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class NarratorTest extends TestCase
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

    private function llm(string $model = 'claude-opus-4-7'): LlmNarrator
    {
        return new LlmNarrator(
            app(Factory::class),
            new Repository(new ArrayStore),
            new TemplateNarrator,
            app(LoggerInterface::class),
            'sk-test',
            $model,
        );
    }

    public function test_the_api_narrator_calls_claude_and_returns_the_prose(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'A saga of the oasis.']]]),
        ]);

        $prose = $this->llm()->retell($this->saga());

        $this->assertSame('A saga of the oasis.', $prose);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.anthropic.com/v1/messages'
            && $request['model'] === 'claude-opus-4-7'
            && $request->hasHeader('x-api-key', 'sk-test')
            && $request->hasHeader('anthropic-version', '2023-06-01')
            && $request['system'][0]['cache_control']['type'] === 'ephemeral');
    }

    public function test_the_api_narrator_caches_by_fingerprint(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'Once told.']]]),
        ]);

        $narrator = $this->llm();
        $narrator->retell($this->saga());
        $narrator->retell($this->saga());

        Http::assertSentCount(1);
    }

    public function test_the_api_narrator_falls_back_to_the_template_on_failure(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'overloaded']], 529)]);

        $prose = $this->llm()->retell($this->saga());

        $this->assertStringContainsString('Sunwell Oasis', $prose);
    }

    public function test_it_resolves_to_the_template_by_default(): void
    {
        config(['services.narrator.driver' => 'template']);

        $this->assertInstanceOf(TemplateNarrator::class, app(Narrator::class));
    }

    public function test_the_api_driver_needs_a_key_otherwise_falls_back_to_the_template(): void
    {
        config(['services.narrator.driver' => 'api', 'services.anthropic.key' => null]);
        $this->assertInstanceOf(TemplateNarrator::class, app(Narrator::class));

        config(['services.anthropic.key' => 'sk-test']);
        $this->assertInstanceOf(LlmNarrator::class, app(Narrator::class));
    }

    public function test_the_claude_code_driver_resolves_to_the_cli_narrator(): void
    {
        config(['services.narrator.driver' => 'claude_code']);

        $this->assertInstanceOf(ClaudeCodeNarrator::class, app(Narrator::class));
    }
}
