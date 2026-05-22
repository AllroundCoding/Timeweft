<?php

namespace App\Sim\Causality;

use App\Sim\Chronicle\ChronicleEvent;

/**
 * The diff between the true history and a counterfactual one: which recorded
 * events the edit **erased**, which it let newly **emerge**, and the tick at
 * which the two timelines first **diverge**. Everything before that tick is
 * identical — the legible-ripple guarantee (design doc 09): an edit only
 * disturbs its downstream cone, not the whole world.
 */
final class RippleResult
{
    /**
     * @param  list<ChronicleEvent>  $erased  events in the true history absent from the counterfactual
     * @param  list<ChronicleEvent>  $emerged  events the edit made newly possible
     */
    public function __construct(
        public readonly array $erased,
        public readonly array $emerged,
        public readonly ?int $divergesAtTick,
    ) {}

    /**
     * @param  list<ChronicleEvent>  $trueHistory
     * @param  list<ChronicleEvent>  $counterfactual
     */
    public static function between(array $trueHistory, array $counterfactual): self
    {
        $key = static fn (ChronicleEvent $e): string => $e->tick.'|'.$e->type.'|'.$e->text;

        $cfKeys = array_fill_keys(array_map($key, $counterfactual), true);
        $trueKeys = array_fill_keys(array_map($key, $trueHistory), true);

        $erased = array_values(array_filter($trueHistory, static fn (ChronicleEvent $e): bool => ! isset($cfKeys[$key($e)])));
        $emerged = array_values(array_filter($counterfactual, static fn (ChronicleEvent $e): bool => ! isset($trueKeys[$key($e)])));

        $ticks = array_map(static fn (ChronicleEvent $e): int => $e->tick, [...$erased, ...$emerged]);

        return new self($erased, $emerged, $ticks === [] ? null : min($ticks));
    }

    public function changed(): bool
    {
        return $this->erased !== [] || $this->emerged !== [];
    }

    public function summary(): string
    {
        if (! $this->changed()) {
            return 'The edit changed nothing — the event had no downstream cone.';
        }

        return sprintf(
            'The edit erased %d event(s) and let %d new one(s) emerge; history first diverges at tick %d.',
            count($this->erased), count($this->emerged), $this->divergesAtTick ?? 0,
        );
    }
}
