<?php

namespace App\Sim\Institutions;

/**
 * A settlement-scale structure that emerges when organic cohesion can no longer
 * meet cooperation demand. It supplies the paid-to/forced-to participation that
 * want-to (cohesion × disposition) alone cannot — closing the cooperation deficit
 * (design doc 07). Culture picks the type; until the culture vector (doc 11) is
 * built, the region stands in for it.
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

    /** Culture (region, for now) picks the archetype the deficit gives rise to. */
    public static function emergeFor(string $region, int $foundedTick): self
    {
        // Tharados: a harsh desert breeds piety and tradition, so faith becomes the
        // cooperation technology (Norenzayan's "Big Gods"). Other cultures will give
        // rise to councils, guilds, or magistrates once the culture vector drives it.
        return match ($region) {
            'Tharados' => new self('Temple of Nara', 'temple', $foundedTick, mandate: 0.55),
            default => new self('village council', 'council', $foundedTick, mandate: 0.40),
        };
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
