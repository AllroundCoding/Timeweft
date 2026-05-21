<?php

namespace App\Sim\Culture;

use App\Sim\World\Agent;

/**
 * A faith expressed as a weighting (0..100) over Haidt's six Moral Foundations — care, fairness,
 * loyalty, authority, sanctity, liberty. Its tenets and taboos are simply the foundations it
 * weights most heavily, and `binding` (from the culture's piety) is how strongly those tenets
 * actually steer behavior. Generated from the culture vector (design doc 11): a collectivist,
 * hierarchical, traditional, pious culture grows a binding faith (loyalty/authority/sanctity);
 * an individualist, egalitarian one leans on the individualizing foundations (care/fairness/liberty).
 *
 * This is the structured source the faith-tenet disposition modifiers read from; on its own it is
 * inert (no behavior change yet).
 */
final class Faith
{
    /** @var list<string> the six foundations, in display order */
    public const FOUNDATIONS = ['care', 'fairness', 'loyalty', 'authority', 'sanctity', 'liberty'];

    public function __construct(
        public readonly string $name,
        public readonly float $care,
        public readonly float $fairness,
        public readonly float $loyalty,
        public readonly float $authority,
        public readonly float $sanctity,
        public readonly float $liberty,
        public readonly float $binding,
    ) {}

    public static function fromCulture(string $name, Culture $culture): self
    {
        $clamp = static fn (float $v): float => max(0.0, min(100.0, $v));

        return new self(
            name: $name,
            care: $clamp(50.0 + ($culture->collectivism - 50.0) * 0.3),     // compassion for the in-group
            fairness: $clamp(80.0 - $culture->hierarchy * 0.5),             // egalitarian → fairness
            loyalty: $clamp(25.0 + $culture->collectivism * 0.65),          // in-group binding
            authority: $clamp(25.0 + $culture->hierarchy * 0.65),           // respect for rank
            sanctity: $clamp(($culture->tradition + $culture->piety) / 2.0), // purity / the sacred
            liberty: $clamp(90.0 - $culture->hierarchy * 0.4 - $culture->collectivism * 0.3), // anti-domination
            binding: max(0.0, min(1.0, $culture->piety / 100.0)),
        );
    }

    /** @return array<string,float> */
    public function vector(): array
    {
        return [
            'care' => $this->care,
            'fairness' => $this->fairness,
            'loyalty' => $this->loyalty,
            'authority' => $this->authority,
            'sanctity' => $this->sanctity,
            'liberty' => $this->liberty,
        ];
    }

    /**
     * The foundations this faith weights most heavily — its defining tenets and taboos.
     *
     * @return list<string>
     */
    public function tenets(int $top = 2): array
    {
        $vector = $this->vector();
        arsort($vector);

        return array_slice(array_keys($vector), 0, max(1, $top));
    }

    /** The nudge this faith's tenets apply to a disposition, before per-agent adherence. */
    public function dispositionModifier(string $key): float
    {
        return match ($key) {
            'thrift' => ($this->sanctity - 50.0) * 0.4,                          // asceticism / purity → discipline
            'generosity' => (($this->care + $this->loyalty) / 2.0 - 50.0) * 0.4, // compassion + in-group → giving
            default => 0.0,
        };
    }

    /**
     * How devoutly a specific agent actually lives the faith (0..1): the culture's piety (the
     * professed ceiling), moderated by the individual's conscientiousness and agreeableness (≈
     * generosity). This is why professed belief and lived practice diverge — the nominal believer
     * binds near 0 even where the culture is pious.
     */
    public function adherenceOf(Agent $agent): float
    {
        $conscientiousness = (float) ($agent->trait('conscientiousness') ?? 50.0);
        $agreeableness = (float) ($agent->trait('generosity') ?? 50.0);

        return $this->binding * (($conscientiousness + $agreeableness) / 200.0);
    }

    /** A disposition as this agent actually lives it: base trait shaped by the faith × how much they follow it. */
    public function shape(Agent $agent, string $key, float $base): float
    {
        return max(0.0, min(100.0, $base + $this->dispositionModifier($key) * $this->adherenceOf($agent)));
    }

    /**
     * How strongly this faith pulls its followers toward in-group cooperation (0..1) — the binding
     * foundations loyalty + authority. Norenzayan's "Big Gods as a cooperation technology": the
     * devout pitch in for the community even unbidden.
     */
    public function cooperativePull(): float
    {
        return ($this->loyalty + $this->authority) / 200.0;
    }
}
