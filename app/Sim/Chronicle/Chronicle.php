<?php

namespace App\Sim\Chronicle;

/**
 * The sparse, canonical record of notable events — the "skeleton" the timeline
 * is built from. Dense per-tick activity is texture and stays out of here.
 */
final class Chronicle
{
    /** @var list<array{tick:int,text:string}> */
    private array $entries = [];

    public function record(int $tick, string $text): void
    {
        $this->entries[] = ['tick' => $tick, 'text' => $text];
    }

    /** @return list<array{tick:int,text:string}> */
    public function all(): array
    {
        return $this->entries;
    }
}
