<?php

namespace App\Sim;

use App\Sim\Chronicle\ChronicleEvent;
use App\Sim\Direction\Milestone;
use App\Sim\Persistence\Checkpoint;
use App\Sim\Persistence\Timeline;
use App\Sim\Persistence\WorldSkeleton;
use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\World\Agent;
use App\Sim\World\World;

/**
 * The engine's public API surface (TWT-88; design doc 01) — the curated **seed · advance · query · steer**
 * entry point everything outside the core drives the simulation through: the `world:simulate` command, a
 * renderer, the LLM flavor layer, persistence, the future game layer. A façade over {@see World} so
 * callers code against a stable, intention-revealing surface rather than poking at engine internals.
 *
 * Framework-agnostic and deterministic. As it advances it anchors checkpoints on a cadence into a
 * {@see Timeline}, so `query`ing any past tick is cheap derive-on-demand (TWT-38) rather than a replay
 * from t=0. Persistence stays at the boundary: hand {@see World} (or {@see skeleton}) to a WorldStore.
 */
final class Engine
{
    private const TICKS_PER_YEAR = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

    private function __construct(
        private readonly World $world,
        private readonly Timeline $timeline,
        private readonly int $checkpointEvery,
    ) {}

    // ── seed ──────────────────────────────────────────────────────────────────────────────────────

    /** Seed a fresh world from a reproducible seed; the same seed always grows the same world. */
    public static function seed(string|int $seed, int $population = 8, int $checkpointEvery = self::TICKS_PER_YEAR): self
    {
        $world = World::seedTharadosVillage(new Rng($seed), $population);
        $timeline = new Timeline;
        $timeline->record($world); // anchor the origin so any tick can be reconstructed

        return new self($world, $timeline, max(1, $checkpointEvery));
    }

    // ── advance ───────────────────────────────────────────────────────────────────────────────────

    /** Run the world forward, anchoring a checkpoint every cadence so later queries stay cheap. */
    public function advance(int $ticks): self
    {
        $target = $this->world->tick + max(0, $ticks);
        while ($this->world->tick < $target) {
            $this->world->advance(min($this->checkpointEvery, $target - $this->world->tick));
            $this->timeline->record($this->world);
        }

        return $this;
    }

    // ── query ─────────────────────────────────────────────────────────────────────────────────────

    public function tick(): int
    {
        return $this->world->tick;
    }

    /** @return list<ChronicleEvent> the canonical timeline so far */
    public function chronicle(): array
    {
        return $this->world->chronicle->all();
    }

    /** @return list<Agent> every living person across all settlements */
    public function livingAgents(): array
    {
        $living = [];
        foreach ($this->world->villages as $village) {
            foreach ($village->livingAgents() as $agent) {
                $living[] = $agent;
            }
        }

        return $living;
    }

    public function population(): int
    {
        return count($this->livingAgents());
    }

    /** @return list<Agent> every agent across all settlements — living and dead (the full cast a timeline renders) */
    public function agents(): array
    {
        $all = [];
        foreach ($this->world->villages as $village) {
            foreach ($village->agents as $agent) {
                $all[] = $agent;
            }
        }

        return $all;
    }

    /** @return list<Milestone> the authored beats the story director is steering the world toward */
    public function milestones(): array
    {
        return $this->world->milestones;
    }

    /** The canonical persistable state right now (for persistence/inspection). */
    public function skeleton(): WorldSkeleton
    {
        return $this->world->skeleton();
    }

    /** The world as it stood at a past tick — derive-on-demand from the nearest checkpoint (TWT-38). */
    public function at(int $tick): World
    {
        return $this->timeline->reconstructAt($tick);
    }

    // ── steer ─────────────────────────────────────────────────────────────────────────────────────

    /** Pin an authored beat for the story director to steer the world toward (design doc 08). */
    public function pin(Milestone $milestone): self
    {
        $this->world->milestones[] = $milestone;

        return $this;
    }

    /**
     * Mark settlements salient (by name) so the LOD manager keeps — or makes — them tracked at full
     * per-agent detail: a folded settlement regains its individuals on the next reconcile (TWT-248).
     * Replaces the prior set each call; the primary settlement is always tracked. Detail follows attention.
     */
    public function attend(string ...$names): self
    {
        $this->world->setSalient(...$names);

        return $this;
    }

    // ── escape hatch ────────────────────────────────────────────────────────────────────────────────

    /** The live world — for the boundary to persist (WorldStore) or checkpoint; not for reaching past the API. */
    public function world(): World
    {
        return $this->world;
    }

    public function checkpoint(): Checkpoint
    {
        return $this->world->checkpoint();
    }
}
