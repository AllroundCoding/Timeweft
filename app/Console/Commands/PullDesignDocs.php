<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

#[Signature('docs:pull-linear {--dry-run : Report what would change without writing files}')]
#[Description('Pull the design docs from their Linear Documents into docs/design/ (Linear is canonical)')]
class PullDesignDocs extends Command
{
    private const ENDPOINT = 'https://api.linear.app/graphql';

    private const DOCS_DIR = 'docs/design';

    public function handle(): int
    {
        $key = (string) config('services.linear.key');
        $projectId = (string) config('services.linear.project_id');

        if ($key === '') {
            $this->error('LINEAR_API_KEY is not set — cannot reach Linear.');

            return self::FAILURE;
        }

        $response = Http::withHeaders(['Authorization' => $key])
            ->post(self::ENDPOINT, ['query' => $this->query()]);

        if ($response->failed() || $response->json('errors') !== null) {
            $this->error('Linear API request failed: '.$response->body());

            return self::FAILURE;
        }

        $documents = $response->json('data.documents.nodes') ?? [];
        $dryRun = (bool) $this->option('dry-run');
        $written = 0;

        foreach ($documents as $document) {
            // Filter to this project client-side (avoids assuming the document-filter schema).
            if (($document['project']['id'] ?? null) !== $projectId) {
                continue;
            }

            $content = (string) ($document['content'] ?? '');
            $number = $this->designDocNumber($content);
            if ($number === null) {
                continue; // not a numbered design doc — leave it alone
            }

            $path = $this->pathFor($number, $content);
            $body = rtrim($content)."\n";

            if (is_file($path) && rtrim((string) file_get_contents($path)) === rtrim($body)) {
                continue; // already in sync
            }

            $this->line(($dryRun ? '[dry-run] ' : '').'writing '.$path);
            if (! $dryRun) {
                file_put_contents($path, $body);
            }
            $written++;
        }

        $this->info($dryRun
            ? "{$written} design doc(s) would change."
            : "{$written} design doc(s) synced from Linear.");

        return self::SUCCESS;
    }

    /** The leading number of a design doc's H1 (e.g. "# 12 · Goods…" → "12"), or null. */
    private function designDocNumber(string $content): ?string
    {
        if (preg_match('/^#\s+(\d{1,2})\s+·/m', $content, $m) === 1) {
            return str_pad($m[1], 2, '0', STR_PAD_LEFT);
        }

        return null;
    }

    /** Preserve the existing file name for a known doc; otherwise derive one from the H1 title. */
    private function pathFor(string $number, string $content): string
    {
        $existing = glob(base_path(self::DOCS_DIR."/{$number}-*.md"));
        if ($existing !== false && $existing !== []) {
            return $existing[0];
        }

        $title = preg_match('/^#\s+\d{1,2}\s+·\s+(.+)$/m', $content, $m) === 1 ? $m[1] : 'untitled';
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($title)) ?? '', '-');

        return base_path(self::DOCS_DIR."/{$number}-{$slug}.md");
    }

    private function query(): string
    {
        return <<<'GQL'
        query DesignDocs {
          documents(first: 250) {
            nodes { id title content project { id } }
          }
        }
        GQL;
    }
}
