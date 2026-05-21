<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

#[Signature('docs:push-linear {--dry-run : Report what would change without writing to Linear}')]
#[Description('Seed/refresh the design docs as Linear Documents from docs/design/ (one-time bootstrap; Linear is canonical thereafter)')]
class PushDesignDocs extends Command
{
    private const ENDPOINT = 'https://api.linear.app/graphql';

    private const DOCS_DIR = 'docs/design';

    public function handle(): int
    {
        $key = (string) config('services.linear.key');
        $projectId = (string) config('services.linear.project_id');

        if ($key === '' || $projectId === '') {
            $this->error('LINEAR_API_KEY and LINEAR_PROJECT_ID must be set.');

            return self::FAILURE;
        }

        $existing = $this->existingDocumentsByNumber($key, $projectId);
        if ($existing === null) {
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $pushed = 0;

        foreach (glob(base_path(self::DOCS_DIR.'/[0-9][0-9]-*.md')) ?: [] as $path) {
            $content = rtrim((string) file_get_contents($path))."\n";
            if (preg_match('/^#\s+(\d{1,2})\s+·\s+(.+)$/m', $content, $m) !== 1) {
                continue;
            }
            $number = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            $title = trim($m[2]);
            $id = $existing[$number] ?? null;

            $this->line(($dryRun ? '[dry-run] ' : '').($id ? 'updating' : 'creating').' "'.$title.'"');
            if (! $dryRun && ! $this->upsert($key, $projectId, $id, $title, $content)) {
                $this->error('Failed to push "'.$title.'".');

                return self::FAILURE;
            }
            $pushed++;
        }

        $this->info($dryRun ? "{$pushed} doc(s) would be pushed." : "{$pushed} doc(s) pushed to Linear.");

        return self::SUCCESS;
    }

    /** @return array<string,string>|null number => document id, or null on failure */
    private function existingDocumentsByNumber(string $key, string $projectId): ?array
    {
        $response = Http::withHeaders(['Authorization' => $key])->post(self::ENDPOINT, [
            'query' => 'query { documents(first: 250) { nodes { id content project { id } } } }',
        ]);

        if ($response->failed() || $response->json('errors') !== null) {
            $this->error('Linear API request failed: '.$response->body());

            return null;
        }

        $byNumber = [];
        foreach ($response->json('data.documents.nodes') ?? [] as $node) {
            if (($node['project']['id'] ?? null) !== $projectId) {
                continue;
            }
            if (preg_match('/^#\s+(\d{1,2})\s+·/m', (string) ($node['content'] ?? ''), $m) === 1) {
                $byNumber[str_pad($m[1], 2, '0', STR_PAD_LEFT)] = $node['id'];
            }
        }

        return $byNumber;
    }

    private function upsert(string $key, string $projectId, ?string $id, string $title, string $content): bool
    {
        [$query, $variables] = $id === null
            ? [
                'mutation Create($input: DocumentCreateInput!) { documentCreate(input: $input) { success } }',
                ['input' => ['title' => $title, 'content' => $content, 'projectId' => $projectId]],
            ]
            : [
                'mutation Update($id: String!, $input: DocumentUpdateInput!) { documentUpdate(id: $id, input: $input) { success } }',
                ['id' => $id, 'input' => ['title' => $title, 'content' => $content]],
            ];

        $response = Http::withHeaders(['Authorization' => $key])
            ->post(self::ENDPOINT, ['query' => $query, 'variables' => $variables]);

        return $response->successful() && $response->json('errors') === null;
    }
}
