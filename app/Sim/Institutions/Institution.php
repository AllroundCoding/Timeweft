<?php

namespace App\Sim\Institutions;

use App\Sim\Culture\Culture;

/**
 * A settlement-scale structure that emerges when organic cohesion can no longer
 * meet cooperation demand. It supplies the paid-to/forced-to participation that
 * want-to (cohesion × disposition) alone cannot — closing the cooperation deficit
 * (design doc 07). The culture vector picks the type.
 */
final class Institution
{
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly int $foundedTick,
        /** 0..1: how strongly it compels participation beyond organic want-to. */
        public readonly float $mandate,
    ) {}

    /** The culture vector picks the archetype the deficit gives rise to. */
    public static function emergeFor(Culture $culture, int $foundedTick): self
    {
        // A devout culture turns to faith as its cooperation technology (Norenzayan's
        // "Big Gods"); a more secular one convenes a council. Later, hierarchy and the
        // other dimensions will fan this out into guilds, magistrates, and emperors.
        return $culture->piety >= 50.0
            ? new self('Temple of Nara', 'temple', $foundedTick, mandate: 0.55)
            : new self('village council', 'council', $foundedTick, mandate: 0.40);
    }

    /**
     * Effective participation once the institution supplies obligated cooperation:
     * it lifts each contributor toward full participation, filling the gap that
     * organic want-to leaves open.
     */
    public function liftedParticipation(float $wantTo): float
    {
        return $wantTo + $this->mandate * (1.0 - $wantTo);
    }
}
