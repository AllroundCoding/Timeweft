<?php

namespace App\Sim\Culture;

/**
 * A culture as a small vector of dimensions (0..100), synthesized from Hofstede +
 * Schwartz (design doc 11). For now it is hand-seeded; later it will be generated
 * from material conditions and drift with prosperity. This minimal version exists
 * to feed the two systems that already need it: the cohesion baseline and the
 * institution type a cooperation deficit gives rise to.
 */
final class Culture
{
    public function __construct(
        public readonly string $name,
        public readonly float $collectivism,
        public readonly float $hierarchy,
        public readonly float $tradition,
        public readonly float $longTermOrientation,
        public readonly float $restraint,
        public readonly float $achievement,
        public readonly float $piety,
    ) {}

    public static function tharados(): self
    {
        // A harsh desert breeds tight-knit, tradition-bound, ascetic, devout people (Cultural
        // Materialism). Collectivism 85 sets the organic cohesion baseline at 0.85.
        return new self(
            name: 'Tharadi',
            collectivism: 85.0,
            hierarchy: 70.0,
            tradition: 80.0,
            longTermOrientation: 65.0,
            restraint: 75.0,
            achievement: 40.0,
            piety: 80.0,
        );
    }

    /** Organic cooperation baseline (0..1) that the culture's collectivism sets. */
    public function baselineCohesion(): float
    {
        return $this->collectivism / 100.0;
    }

    /** @return array<string,float> */
    public function vector(): array
    {
        return [
            'collectivism' => $this->collectivism,
            'hierarchy' => $this->hierarchy,
            'tradition' => $this->tradition,
            'longTermOrientation' => $this->longTermOrientation,
            'restraint' => $this->restraint,
            'achievement' => $this->achievement,
            'piety' => $this->piety,
        ];
    }
}
