<?php

namespace App\Narrative;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as Http;
use Psr\Log\LoggerInterface;

/**
 * The Anthropic-API narrator (TWT-53): retells the chronicle as saga prose with Claude, via Laravel's
 * HTTP client against the Messages API. This path bills through the Anthropic Developer Platform
 * (separate from a Claude subscription), so it's gated behind a configured API key. The deterministic
 * core stays LLM-free; this lives wholly at the boundary and only *describes* the canonical events.
 *
 * Output is cached app-side by the saga's fingerprint — each moment is generated once and read forever,
 * the primary cost control. Any failure degrades to the template, so the view never breaks.
 */
final class LlmNarrator implements Narrator
{
    use ChroniclerPrompt;

    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    private const ANTHROPIC_VERSION = '2023-06-01';

    public function __construct(
        private readonly Http $http,
        private readonly Cache $cache,
        private readonly TemplateNarrator $fallback,
        private readonly LoggerInterface $log,
        private readonly string $apiKey,
        private readonly string $model,
    ) {}

    public function retell(Saga $saga): string
    {
        return $this->cache->rememberForever(
            "narrative:{$this->model}:{$saga->fingerprint()}",
            fn (): string => $this->generate($saga),
        );
    }

    private function generate(Saga $saga): string
    {
        try {
            $response = $this->http
                ->withHeaders([
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => self::ANTHROPIC_VERSION,
                ])
                ->timeout(60)
                ->retry(2, 1000, throw: false)
                ->post(self::ENDPOINT, [
                    'model' => $this->model,
                    'max_tokens' => 1400,
                    // No thinking, no sampling params: narration is creative writing, not reasoning,
                    // and Opus 4.7 has thinking off by default (and 400s on temperature/top_p/top_k).
                    'system' => [[
                        'type' => 'text',
                        'text' => self::VOICE,
                        'cache_control' => ['type' => 'ephemeral'],
                    ]],
                    'messages' => [[
                        'role' => 'user',
                        'content' => $this->material($saga),
                    ]],
                ]);
        } catch (\Throwable $e) {
            $this->log->warning('LlmNarrator request threw; using template fallback', ['error' => $e->getMessage()]);

            return $this->fallback->retell($saga);
        }

        if ($response->failed()) {
            $this->log->warning('LlmNarrator request failed; using template fallback', ['status' => $response->status()]);

            return $this->fallback->retell($saga);
        }

        $content = $response->json('content');
        $prose = is_array($content) ? $this->firstText($content) : '';

        return $prose !== '' ? $prose : $this->fallback->retell($saga);
    }

    /** @param  array<int|string,mixed>  $content */
    private function firstText(array $content): string
    {
        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                return trim($block['text']);
            }
        }

        return '';
    }
}
