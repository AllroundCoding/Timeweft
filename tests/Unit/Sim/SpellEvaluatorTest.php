<?php

namespace Tests\Unit\Sim;

use App\Sim\Chronicle\Chronicle;
use App\Sim\Magic\CastContext;
use App\Sim\Magic\MagicNode;
use App\Sim\Magic\MagicSchool;
use App\Sim\Magic\NodeTypeRegistry;
use App\Sim\Magic\Spell;
use App\Sim\Magic\SpellEvaluator;
use App\Sim\Magic\Wire;
use App\Sim\Support\Rng;
use PHPUnit\Framework\TestCase;

/**
 * TWT-234 — the spell-graph core. A spell is an immutable, port-typed DAG; the evaluator resolves it to a
 * deterministic effect + cost + backlash, drawing variance only from the forked RNG the caller passes.
 * Pure app/Sim/Magic, not wired into the run loop, so the canonical world stays byte-identical.
 */
class SpellEvaluatorTest extends TestCase
{
    private NodeTypeRegistry $registry;

    private SpellEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->registry = NodeTypeRegistry::standard();
        $this->evaluator = new SpellEvaluator;
    }

    /** A forked sub-stream keyed as the practice layer will key it (concern='magic', caster, tick). */
    private function castStream(): Rng
    {
        return (new Rng('vaeris'))->stream('magic', 7, 500);
    }

    /** field-draw → shape → harm: the minimal castable composition (the Recipe-style base case). */
    private function emberlance(): Spell
    {
        return new Spell('Emberlance', [
            new MagicNode(1, $this->registry->get('field-draw'), magnitude: 10.0, school: MagicSchool::Fire),
            new MagicNode(2, $this->registry->get('shape')),
            new MagicNode(3, $this->registry->get('harm')),
        ], [new Wire(1, 2), new Wire(2, 3)]);
    }

    /** field-draw → amplify ×2 → shape → harm: exercises amplification, cost summing, and backlash. */
    private function pyre(): Spell
    {
        return new Spell('Pyre', [
            new MagicNode(1, $this->registry->get('field-draw'), magnitude: 10.0, school: MagicSchool::Fire),
            new MagicNode(2, $this->registry->get('amplify'), magnitude: 2.0),
            new MagicNode(3, $this->registry->get('shape')),
            new MagicNode(4, $this->registry->get('harm')),
        ], [new Wire(1, 2), new Wire(2, 3), new Wire(3, 4)]);
    }

    public function test_a_minimal_spell_resolves_to_its_effect(): void
    {
        $outcome = $this->evaluator->evaluate($this->emberlance(), new CastContext(0.5, 100.0), $this->castStream());

        $this->assertSame('harm', $outcome->effect);
        $this->assertSame(MagicSchool::Fire, $outcome->school);
        $this->assertEqualsWithDelta(10.0, $outcome->magnitude, 0.5, 'effect ≈ the drawn magnitude, within the RNG flux');
        $this->assertFalse($outcome->overloaded(), 'ample supply → no backlash');
    }

    public function test_the_cast_is_deterministic(): void
    {
        $context = new CastContext(0.6, 8.0, 2.0);

        $this->assertEquals(
            $this->evaluator->evaluate($this->pyre(), $context, $this->castStream()),
            $this->evaluator->evaluate($this->pyre(), $context, $this->castStream()),
            'same spell + state + forked stream → identical outcome',
        );
    }

    public function test_port_typing_rejects_an_incompatible_wiring(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // Raw Energy from a source cannot feed a sink directly — it must be shaped first.
        new Spell('Malformed', [
            new MagicNode(1, $this->registry->get('field-draw'), magnitude: 5.0),
            new MagicNode(2, $this->registry->get('harm')),
        ], [new Wire(1, 2)]);
    }

    public function test_a_spell_must_have_exactly_one_sink(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Spell('Sinkless', [
            new MagicNode(1, $this->registry->get('field-draw'), magnitude: 5.0),
            new MagicNode(2, $this->registry->get('shape')),
        ], [new Wire(1, 2)]);
    }

    public function test_cost_sums_across_the_graph(): void
    {
        // With supply far above demand, the whole cost is drawn cleanly: 10 (source) + 10 (amplify to 20)
        // + 1 (shape) + 1 (harm) = 22.
        $outcome = $this->evaluator->evaluate($this->pyre(), new CastContext(0.5, 1_000.0), $this->castStream());

        $this->assertEqualsWithDelta(22.0, $outcome->resourcesConsumed, 1e-6);
        $this->assertEqualsWithDelta(0.0, $outcome->backlash, 1e-6);
        $this->assertEqualsWithDelta(20.0, $outcome->magnitude, 1.0, 'amplified to ≈20');
    }

    public function test_overload_produces_backlash(): void
    {
        // Cost 22 against a clean supply of 10 → 12 of backlash.
        $outcome = $this->evaluator->evaluate($this->pyre(), new CastContext(0.5, 10.0), $this->castStream());

        $this->assertTrue($outcome->overloaded());
        $this->assertEqualsWithDelta(12.0, $outcome->backlash, 1e-6);
        $this->assertEqualsWithDelta(10.0, $outcome->resourcesConsumed, 1e-6, 'only the clean supply is drawn');
    }

    public function test_skill_softens_the_backlash_strain(): void
    {
        $unskilled = $this->evaluator->evaluate($this->pyre(), new CastContext(0.0, 10.0), $this->castStream());
        $skilled = $this->evaluator->evaluate($this->pyre(), new CastContext(1.0, 10.0), $this->castStream());

        $this->assertGreaterThan(0.0, $skilled->casterStrain);
        $this->assertGreaterThan($skilled->casterStrain, $unskilled->casterStrain, 'the novice pays more for the same overload');
    }

    public function test_a_cast_records_a_provenance_bearing_event(): void
    {
        $chronicle = new Chronicle;
        $outcome = $this->evaluator->evaluate($this->pyre(), new CastContext(0.5, 10.0), $this->castStream());

        $event = $outcome->recordInto($chronicle, tick: 500, casterId: 42);

        $this->assertSame('cast', $event->type);
        $this->assertSame([42], $event->subjects);
        $this->assertContains('harm', $event->factors);
        $this->assertContains('fire', $event->factors);
        $this->assertContains('backlash', $event->factors, 'an overloaded cast records its backlash');
        $this->assertStringContainsString('Pyre', $event->text);
        $this->assertSame($event, $chronicle->last());
    }
}
