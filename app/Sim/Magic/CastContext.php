<?php

namespace App\Sim\Magic;

/**
 * The world-state a spell is cast into — the inputs the {@see SpellEvaluator} reads. Supplied by the
 * caller (later, the practice layer reads these off the caster and the local thaumic field); the
 * evaluator itself stays a pure function of (spell, context, RNG).
 */
final readonly class CastContext
{
    public function __construct(
        /** The caster's proficiency, 0..1 — higher turns less of a spell's backlash into bodily strain. */
        public float $casterSkill = 0.5,
        /** Clean magical supply from the local thaumic field. */
        public float $thaumicStrength = 0.0,
        /** Clean magical supply from crystals the caster holds. */
        public float $crystalReserve = 0.0,
    ) {}

    /** The clean current available before the caster must pay the shortfall as backlash. */
    public function cleanSupply(): float
    {
        return max(0.0, $this->thaumicStrength + $this->crystalReserve);
    }
}
