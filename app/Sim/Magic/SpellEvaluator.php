<?php

namespace App\Sim\Magic;

use App\Sim\Support\Rng;

/**
 * The headless heart of the magic system (design doc 20): given a spell, the world-state it is cast into,
 * and a forked RNG sub-stream, it walks the graph and resolves a {@see CastOutcome} — effect magnitude,
 * resources consumed, caster strain, and backlash. Pure: the same (spell, context, RNG) always yields the
 * same result, and variance enters only through the caller's forked stream (keyed concern='magic', caster,
 * tick), so magic never perturbs the seeded births, deaths, or harvests.
 *
 * The cost model conserves current: a source's draw is paid in full, an amplify pays for the power it
 * conjures, and shaping/release add fixed overhead — so the total demand is the effect magnitude plus
 * overhead. Clean supply (thaumic field + crystals) covers what it can; the shortfall is backlash.
 */
final class SpellEvaluator
{
    public function evaluate(Spell $spell, CastContext $context, Rng $rng): CastOutcome
    {
        /** @var array<int,float> $magnitude current flowing out of each node */
        $magnitude = [];
        /** @var array<int,?MagicSchool> $school the current's school at each node */
        $school = [];
        $totalCost = 0.0;

        foreach ($spell->topologicalOrder() as $id) {
            $node = $spell->node($id);

            $incomingMagnitude = 0.0;
            $incomingSchool = null;
            $strongest = -1.0;
            foreach ($spell->inputsTo($id) as $sourceId) {
                $incomingMagnitude += $magnitude[$sourceId];
                if ($magnitude[$sourceId] > $strongest) {
                    $strongest = $magnitude[$sourceId];
                    $incomingSchool = $school[$sourceId];
                }
            }

            switch ($node->type->kind) {
                case NodeKind::Source:
                    $out = max(0.0, $node->magnitude);
                    $totalCost += $out; // a source's cost is the current it pulls from the supply
                    $magnitude[$id] = $out;
                    $school[$id] = $node->school;
                    break;

                case NodeKind::Transform:
                    if ($node->type->amplifies) {
                        $out = $incomingMagnitude * max(1.0, $node->magnitude);
                        $totalCost += $out - $incomingMagnitude; // pay for the power conjured
                    } else {
                        $out = $incomingMagnitude;
                        $totalCost += $node->type->baseCost;
                    }
                    $magnitude[$id] = $out;
                    $school[$id] = $node->school ?? $incomingSchool; // school-convert overrides; others pass through
                    break;

                case NodeKind::Sink:
                    $totalCost += $node->type->baseCost;
                    $magnitude[$id] = $incomingMagnitude;
                    $school[$id] = $incomingSchool;
                    break;
            }
        }

        $sink = $spell->sink();

        // A small deterministic flux on the realised effect — drawn from the caller's forked stream alone.
        $effectMagnitude = $magnitude[$sink->id] * $rng->float(0.95, 1.05);

        $supply = $context->cleanSupply();
        $backlash = max(0.0, $totalCost - $supply);
        $skill = max(0.0, min(1.0, $context->casterSkill));

        return new CastOutcome(
            spellName: $spell->name,
            effect: $sink->type->name,
            school: $school[$sink->id],
            magnitude: $effectMagnitude,
            resourcesConsumed: min($totalCost, $supply),
            casterStrain: $backlash * (1.0 - 0.5 * $skill),
            backlash: $backlash,
        );
    }
}
