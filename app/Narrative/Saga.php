<?php

namespace App\Narrative;

/**
 * The canonical material a {@see Narrator} retells: a settlement's chronicle over a span of years, plus
 * the context that frames it. A read-only view assembled at the boundary from the engine's query
 * surface — the narrator describes this and nothing more.
 */
final class Saga
{
    /**
     * @param  list<array{year:int,text:string,type:string}>  $events  the chronicle, in tick order
     * @param  array{founders:int,born:int,died:int,living:int}  $population
     */
    public function __construct(
        public readonly string $world,
        public readonly string $region,
        public readonly string $seed,
        public readonly int $startYear,
        public readonly int $endYear,
        public readonly array $events,
        public readonly array $population,
    ) {}

    /**
     * A stable digest of the canonical material — the cache anchor that lets a narrator serve the same
     * prose for the same moment every time. Because the chronicle is deterministic per seed, the same
     * run always fingerprints identically.
     */
    public function fingerprint(): string
    {
        return hash('xxh128', serialize([
            $this->world, $this->region, $this->seed,
            $this->startYear, $this->endYear, $this->events, $this->population,
        ]));
    }
}
