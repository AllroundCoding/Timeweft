<?php

namespace App\Sim\Play;

use App\Sim\Behavior\Activity;

/**
 * The controlled-agent driver (design doc 16; TWT-100) — the headless heart of the realtime playable
 * mode. It turns one tracked agent into "the player" by sourcing its {@see Activity} from **input**
 * rather than the autonomous behaviour stack (doc 04). Everything else in the world keeps acting on its
 * own — the player is one agent among many — so the world stays reproducible: the same seed plus the
 * same commands yields the same world (determinism preserved via the canonical run, doc 09).
 *
 * This is the driver *underneath* realtime mode; the wall-clock cadence, follow-cam, and rendered verbs
 * are the boundary that sets {@see command()} between ticks. A controller is opt-in — a headless run
 * embodies no one, so the canonical world is untouched.
 */
final class PlayerController
{
    public function __construct(
        public readonly int $agentId,
        private ?Activity $command = null,
    ) {}

    /** Direct the player-agent to an activity, overriding autonomy until it is changed or released. */
    public function command(Activity $activity): void
    {
        $this->command = $activity;
    }

    /** Hand the agent back to autonomous behaviour (the behaviour stack resumes deciding for it). */
    public function release(): void
    {
        $this->command = null;
    }

    public function controls(int $agentId): bool
    {
        return $agentId === $this->agentId;
    }

    /**
     * The activity the player has commanded for the given agent — null when this is not the controlled
     * agent, or when no command is standing (autonomy then drives it). The null is what keeps the world
     * hook byte-identical for every other agent.
     */
    public function activityFor(int $agentId): ?Activity
    {
        return $agentId === $this->agentId ? $this->command : null;
    }

    public function commanded(): ?Activity
    {
        return $this->command;
    }
}
