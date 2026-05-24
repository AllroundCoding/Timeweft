<?php

namespace App\Providers;

use App\Narrative\ClaudeCodeNarrator;
use App\Narrative\LlmNarrator;
use App\Narrative\Narrator;
use App\Narrative\TemplateNarrator;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The flavor layer's pluggable seam (TWT-53). The driver picks who narrates:
        //   template    — deterministic, offline, free (the default)
        //   claude_code — your Claude subscription via the local `claude` CLI (browser login, no API key)
        //   api         — the Anthropic API (separate pay-as-you-go billing); needs ANTHROPIC_API_KEY
        // Bound (not a singleton) so config — and tests — take effect on the next resolution.
        $this->app->bind(Narrator::class, function (Application $app): Narrator {
            $template = $app->make(TemplateNarrator::class);

            return match (config('services.narrator.driver')) {
                'claude_code' => new ClaudeCodeNarrator(
                    $app->make(CacheRepository::class),
                    $template,
                    $app->make(LoggerInterface::class),
                    (string) config('services.narrator.claude_bin', 'claude'),
                    $this->stringOrNull(config('services.narrator.claude_model')),
                ),
                'api' => $this->apiNarrator($app, $template),
                default => $template,
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }

    private function apiNarrator(Application $app, TemplateNarrator $template): Narrator
    {
        $key = config('services.anthropic.key');
        if (! is_string($key) || trim($key) === '') {
            return $template;
        }

        return new LlmNarrator(
            $app->make(HttpFactory::class),
            $app->make(CacheRepository::class),
            $template,
            $app->make(LoggerInterface::class),
            $key,
            (string) config('services.anthropic.model', 'claude-opus-4-7'),
        );
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
