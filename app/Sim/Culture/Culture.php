<?php

namespace App\Sim\Culture;

use App\Sim\World\RegionProfile;

/**
 * A culture as a small vector of dimensions (0..100), synthesized from Hofstede +
 * Schwartz (design doc 11). It is *generated from material conditions* (Cultural
 * Materialism) rather than hand-authored, and feeds the cohesion baseline and the
 * institution type a cooperation deficit gives rise to.
 */
final class Culture
{
    /** How strongly an ancestral culture is inherited when generating a derived one (0..1). */
    private const ANCESTRAL_INHERITANCE = 0.5;

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
        $region = RegionProfile::tharados();

        return self::fromMaterialConditions('Tharadi', $region->scarcity(), $region->seasonalVolatility());
    }

    /**
     * Generate a culture from its material conditions — Cultural Materialism (Harris): a harsh,
     * lean, volatile land breeds restraint, tradition, collectivism and piety; an abundant, stable
     * one breeds indulgence, secularism and individualism.
     *
     * The two-way street: when an $ancestral culture is supplied (a daughter settlement, or a
     * culture re-derived as conditions shift), it biases the result — the new culture inherits its
     * forebears and *adapts* toward the local materials rather than starting from a blank slate.
     *
     * @param  float  $scarcity  0 (abundant) .. 1 (harsh) — how lean the land is
     * @param  float  $volatility  0 (stable) .. 1 (swinging) — how much yield swings across seasons
     */
    public static function fromMaterialConditions(
        string $name,
        float $scarcity,
        float $volatility,
        ?self $ancestral = null,
    ): self {
        $clamp = static fn (float $v): float => max(0.0, min(100.0, $v));

        $derived = new self(
            name: $name,
            collectivism: $clamp(40.0 + $scarcity * 60.0),
            hierarchy: $clamp(40.0 + $scarcity * 20.0 + $volatility * 30.0),
            tradition: $clamp(30.0 + $scarcity * 40.0 + $volatility * 40.0),
            longTermOrientation: $clamp(30.0 + $volatility * 70.0),
            restraint: $clamp(30.0 + $scarcity * 60.0),
            achievement: $clamp(70.0 - $scarcity * 40.0),
            piety: $clamp(35.0 + $scarcity * 60.0),
        );

        return $ancestral === null ? $derived : $derived->inheriting($ancestral);
    }

    /** Pull each dimension toward an ancestral culture — cultural inheritance (the feedback half). */
    private function inheriting(self $ancestral): self
    {
        $w = self::ANCESTRAL_INHERITANCE;
        $mix = static fn (float $own, float $anc): float => $own * (1.0 - $w) + $anc * $w;

        return new self(
            name: $this->name,
            collectivism: $mix($this->collectivism, $ancestral->collectivism),
            hierarchy: $mix($this->hierarchy, $ancestral->hierarchy),
            tradition: $mix($this->tradition, $ancestral->tradition),
            longTermOrientation: $mix($this->longTermOrientation, $ancestral->longTermOrientation),
            restraint: $mix($this->restraint, $ancestral->restraint),
            achievement: $mix($this->achievement, $ancestral->achievement),
            piety: $mix($this->piety, $ancestral->piety),
        );
    }

    /** Drift each dimension a fraction of the way toward a target culture (Inglehart drift over time). */
    public function driftedToward(self $target, float $rate): self
    {
        $step = static fn (float $from, float $to): float => $from + ($to - $from) * $rate;

        return new self(
            name: $this->name,
            collectivism: $step($this->collectivism, $target->collectivism),
            hierarchy: $step($this->hierarchy, $target->hierarchy),
            tradition: $step($this->tradition, $target->tradition),
            longTermOrientation: $step($this->longTermOrientation, $target->longTermOrientation),
            restraint: $step($this->restraint, $target->restraint),
            achievement: $step($this->achievement, $target->achievement),
            piety: $step($this->piety, $target->piety),
        );
    }

    /** Organic cooperation baseline (0..1) that the culture's collectivism sets. */
    public function baselineCohesion(): float
    {
        return $this->collectivism / 100.0;
    }

    /**
     * The dispositional / personality nudge a culture applies to a matching agent trait at birth —
     * the cultural half of who someone becomes (the region supplies the physical half, the
     * individual varies around the mean). Restraint breeds thrift; collectivism breeds generosity;
     * tradition resists openness and feeds anxiety; a long planning horizon breeds conscientiousness.
     */
    public function traitModifier(string $key): float
    {
        return match ($key) {
            'thrift' => ($this->restraint - 50.0) * 0.6,
            'generosity' => ($this->collectivism - 50.0) * 0.4,
            'openness' => (50.0 - $this->tradition) * 0.4,
            'conscientiousness' => ($this->longTermOrientation - 50.0) * 0.5,
            'neuroticism' => ($this->tradition - 50.0) * 0.3,
            default => 0.0,
        };
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
