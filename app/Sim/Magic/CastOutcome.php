<?php

namespace App\Sim\Magic;

use App\Sim\Chronicle\Chronicle;
use App\Sim\Chronicle\ChronicleEvent;

/**
 * The result of evaluating a cast: the world-effect produced and what it cost. The outcome is texture, but
 * it carries the provenance a cast needs to enter the chronicle as a first-class causal event (doc 09):
 * which spell, which caster, what was consumed, and whether it backlashed.
 */
final readonly class CastOutcome
{
    public function __construct(
        public string $spellName,
        /** The sink's effect — heal, harm, ward, enchant, alter-environment. */
        public string $effect,
        public ?MagicSchool $school,
        /** The world-effect's strength. */
        public float $magnitude,
        /** Clean supply drawn from the field and crystals (the rest, if any, is {@see $backlash}). */
        public float $resourcesConsumed,
        /** The bodily toll on the caster, from the backlash a skilled caster only partly absorbs. */
        public float $casterStrain,
        /** Demand beyond the clean supply — the overload that strains the caster. */
        public float $backlash,
    ) {}

    public function overloaded(): bool
    {
        return $this->backlash > 0.0;
    }

    /**
     * Record this cast as a provenance-bearing chronicle event: the caster is its subject, and the school,
     * effect, and any backlash are typed factors so the ripple/causality machinery can read it (doc 09).
     */
    public function recordInto(Chronicle $chronicle, int $tick, int $casterId): ChronicleEvent
    {
        $factors = [$this->effect];
        if ($this->school !== null) {
            $factors[] = $this->school->value;
        }
        if ($this->overloaded()) {
            $factors[] = 'backlash';
        }

        $text = sprintf(
            'casts %s — %s%s of magnitude %.1f, drawing %.1f%s',
            $this->spellName,
            $this->effect,
            $this->school !== null ? ' ('.$this->school->value.')' : '',
            $this->magnitude,
            $this->resourcesConsumed,
            $this->overloaded() ? sprintf(', backlash %.1f', $this->backlash) : '',
        );

        return $chronicle->record($tick, $text, 'cast', [$casterId], [], $factors);
    }
}
