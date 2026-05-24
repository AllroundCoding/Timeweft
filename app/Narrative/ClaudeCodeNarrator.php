<?php

namespace App\Narrative;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Process;
use Psr\Log\LoggerInterface;

/**
 * The Claude-subscription narrator (TWT-53): retells the chronicle by shelling out to the local
 * `claude` CLI (Claude Code), which is authenticated by your browser login and billed against your
 * Claude subscription — no API key, no separate pay-as-you-go account. Local by nature: the host must
 * have Claude Code installed and logged in (great for local worldbuilding, not a deployed server).
 *
 * Output is cached app-side by the saga's fingerprint, so each moment is told once. Any failure — the
 * CLI missing, not logged in, a non-zero exit — degrades to the deterministic {@see TemplateNarrator},
 * so the view never breaks.
 */
final class ClaudeCodeNarrator implements Narrator
{
    use ChroniclerPrompt;

    public function __construct(
        private readonly Cache $cache,
        private readonly TemplateNarrator $fallback,
        private readonly LoggerInterface $log,
        private readonly string $bin,
        private readonly ?string $model,
    ) {}

    public function retell(Saga $saga): string
    {
        return $this->cache->rememberForever(
            "narrative:claude-code:{$saga->fingerprint()}",
            fn (): string => $this->generate($saga),
        );
    }

    private function generate(Saga $saga): string
    {
        // `--tools ""` makes it a pure narrator (no Bash/Read/etc.); a neutral cwd keeps the project's
        // CLAUDE.md out of the prompt; OAuth/keychain auth (the Max login) is used by default — we
        // deliberately avoid `--bare`, which would force API-key auth instead.
        $command = [
            $this->bin, '--print', '--output-format', 'text',
            '--tools', '', '--no-session-persistence',
            '--system-prompt', self::VOICE,
        ];
        if ($this->model !== null && $this->model !== '') {
            $command[] = '--model';
            $command[] = $this->model;
        }

        try {
            $result = Process::path(sys_get_temp_dir())
                ->timeout(120)
                // Drop any stray ANTHROPIC_API_KEY (even the empty one the app's .env ships): a set-but-
                // invalid key shadows the browser-login OAuth and 401s. Cleared, the CLI uses the Max login.
                ->env(['ANTHROPIC_API_KEY' => false, 'ANTHROPIC_AUTH_TOKEN' => false])
                ->input($this->material($saga))
                ->run($command);
        } catch (\Throwable $e) {
            $this->log->warning('ClaudeCodeNarrator process threw; using template fallback', ['error' => $e->getMessage()]);

            return $this->fallback->retell($saga);
        }

        if ($result->failed()) {
            $this->log->warning('ClaudeCodeNarrator process failed; using template fallback', ['exit' => $result->exitCode()]);

            return $this->fallback->retell($saga);
        }

        $prose = trim($result->output());

        return $prose !== '' ? $prose : $this->fallback->retell($saga);
    }
}
